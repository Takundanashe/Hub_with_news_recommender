const messagesEl = document.getElementById('chat-messages');
const form = document.getElementById('chat-composer-form');
const input = document.getElementById('chat-input');
const withUser = window.CHAT_WITH;
const statusDotEl = document.querySelector('#peer-status .status-dot');
const statusTextEl = document.getElementById('peer-status-text');

const seenIds = new Set();   // public_ids already rendered, so a poll never duplicates a socket-delivered message (or vice versa)
let lastSeq = 0;             // cursor for "?after=" - only ever fetch what's newer than this
let typingTimeout = null;
let lastRenderedSender = null; // 'mine' | 'theirs' | null - drives the consecutive-bubble grouping
let lastRenderedDateKey = null; // yyyy-mm-dd of the last date-pill shown

/** SQLite's datetime('now') has no timezone marker and is UTC - normalize
 *  before parsing so this doesn't get silently misread as local time. */
function toLocalDate(raw) {
  if (!raw) return null;
  const iso = raw.includes('T') ? raw : raw.replace(' ', 'T') + 'Z';
  const d = new Date(iso);
  return isNaN(d.getTime()) ? null : d;
}
function formatTime(raw) {
  const d = toLocalDate(raw);
  return d ? d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';
}
function dateKey(d) {
  return `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`;
}
function formatDatePill(d) {
  const today = new Date();
  if (dateKey(d) === dateKey(today)) return 'Today';
  const yesterday = new Date(today); yesterday.setDate(today.getDate() - 1);
  if (dateKey(d) === dateKey(yesterday)) return 'Yesterday';
  return d.toLocaleDateString([], { month: 'short', day: 'numeric' });
}

function isNearBottom() {
  return messagesEl.scrollHeight - messagesEl.scrollTop - messagesEl.clientHeight < 120;
}

function maybeInsertDatePill(createdAt) {
  const d = toLocalDate(createdAt) || new Date();
  const key = dateKey(d);
  if (key === lastRenderedDateKey) return;
  lastRenderedDateKey = key;
  lastRenderedSender = null; // a new day always starts a fresh visual group
  const pill = document.createElement('div');
  pill.className = 'date-pill';
  pill.textContent = formatDatePill(d);
  messagesEl.appendChild(pill);
}

function renderMessage(msg, { stickToBottom = true } = {}) {
  if (msg.id && seenIds.has(msg.id)) return null;
  if (msg.id) seenIds.add(msg.id);
  if (msg.seq && msg.seq > lastSeq) lastSeq = msg.seq;

  const wasNearBottom = isNearBottom();
  maybeInsertDatePill(msg.created_at);

  const sender = msg.mine ? 'mine' : 'theirs';
  const div = document.createElement('div');
  div.className = 'msg-bubble ' + sender + (sender === lastRenderedSender ? ' grouped' : '');
  lastRenderedSender = sender;
  if (msg.id) div.dataset.msgId = msg.id;

  const bodyEl = document.createElement('div');
  bodyEl.className = 'msg-body';
  bodyEl.textContent = msg.body; // textContent, never innerHTML - avoids XSS from message content
  div.appendChild(bodyEl);

  const timeEl = document.createElement('span');
  timeEl.className = 'msg-time';
  timeEl.textContent = formatTime(msg.created_at);
  if (msg.mine) {
    const seenEl = document.createElement('span');
    seenEl.className = 'msg-seen' + (msg.seen ? ' is-read' : '');
    seenEl.textContent = msg.seen ? '✓✓' : '✓';
    timeEl.appendChild(document.createTextNode(' '));
    timeEl.appendChild(seenEl);
  }
  div.appendChild(timeEl);

  messagesEl.appendChild(div);

  // Keep scroll position: only auto-scroll to the new message if the
  // reader was already at (or near) the bottom - if they've scrolled up
  // to read history, an incoming message shouldn't yank them back down.
  if (stickToBottom && wasNearBottom) {
    messagesEl.scrollTop = messagesEl.scrollHeight;
  }
  return div;
}

function setPeerStatus(online) {
  if (statusDotEl) statusDotEl.classList.toggle('online', online);
  if (statusTextEl && !statusTextEl.dataset.typing) statusTextEl.textContent = online ? 'Online' : 'Offline';
}

function showTyping() {
  if (!statusTextEl) return;
  statusTextEl.dataset.typing = '1';
  statusTextEl.innerHTML = '<span class="typing-dots"><span></span><span></span><span></span></span>';
  clearTimeout(typingTimeout);
  typingTimeout = setTimeout(() => {
    delete statusTextEl.dataset.typing;
    // Fall back to whatever the online dot currently shows rather than guessing -
    // the next poll/socket update corrects it if it's gone stale in the meantime.
    statusTextEl.textContent = statusDotEl?.classList.contains('online') ? 'Online' : 'Offline';
  }, 3000);
}

// GET latest_message_id -> only fetch newer messages -> append -> keep
// scroll position. This is the reliability layer: it works whether or not
// the WebSocket happens to be connected, so a message never gets stuck
// waiting on a socket that dropped.
async function fetchNew({ stickToBottom = true } = {}) {
  const res = await fetch(`/actions/dm_fetch.php?with=${encodeURIComponent(withUser)}&after=${lastSeq}`, { credentials: 'same-origin' });
  if (!res.ok) return;
  const data = await res.json();
  if (lastSeq === 0 && data.other) setPeerStatus(data.other.status === 'Active now' || data.other.status === 'active');
  (data.messages || []).forEach((m) => renderMessage(m, { stickToBottom }));
}

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  const body = input.value.trim();
  if (!body) return;
  input.value = '';

  // Render immediately - don't let a slow network or a malformed JSON
  // response hide a message that's actually about to be sent.
  const bubble = renderMessage({ body, mine: true, created_at: new Date().toISOString() });
  if (bubble) bubble.style.opacity = '0.6';

  const fd = new FormData(form);
  fd.set('recipient_id', withUser);
  fd.set('body', body);

  try {
    const res = await fetch('/actions/dm_send.php', { method: 'POST', body: fd, credentials: 'same-origin' });
    const text = await res.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch {
      throw new Error('Unexpected server response.');
    }
    if (!res.ok || !data.success) {
      throw new Error(data.error || 'Message failed to send.');
    }
    if (bubble) {
      bubble.style.opacity = '1';
      if (data.message_id) { bubble.dataset.msgId = data.message_id; seenIds.add(data.message_id); }
      if (data.seq && data.seq > lastSeq) lastSeq = data.seq; // this is the actual double-send fix - without
      // advancing the cursor here, the fetchNew() call below would re-fetch and
      // re-render the very message this bubble already shows.
    }
    fetchNew(); // pick up anything ELSE new right away
  } catch (err) {
    if (bubble) {
      bubble.style.opacity = '0.5';
      bubble.title = 'Failed to send - tap to retry';
      bubble.style.cursor = 'pointer';
      bubble.onclick = () => { bubble.remove(); input.value = body; input.focus(); };
    }
    console.error(err);
  }
});

document.getElementById('emoji-btn')?.addEventListener('click', () => {
  input.value += '😊';
  input.focus();
});

document.getElementById('peer-more-btn')?.addEventListener('click', () => {
  window.openUserActionSheet?.(window.CHAT_WITH_PUBLIC_ID);
});

let typingSendThrottle = 0;
input.addEventListener('input', () => {
  const now = Date.now();
  if (now - typingSendThrottle < 1500) return;
  typingSendThrottle = now;
  sendTyping();
});

let socketRef = null;
function sendTyping() {
  if (socketRef && socketRef.readyState === WebSocket.OPEN) {
    socketRef.send(JSON.stringify({ type: 'typing', recipient_public_id: withUser }));
  }
}

// --- Real-time push, with the polling above as the fallback that actually
// guarantees delivery regardless of socket state. ---
function connectSocket() {
  if (!window.WS_TOKEN) return;
  const proto = location.protocol === 'https:' ? 'wss' : 'ws';
  const socket = new WebSocket(`${proto}://${location.host}/ws?token=${encodeURIComponent(window.WS_TOKEN)}`);
  socketRef = socket;

  socket.onmessage = (event) => {
    const data = JSON.parse(event.data);
    if (data.type === 'dm' && data.from && data.from.public_id === withUser) {
      renderMessage({ body: data.body, mine: false, created_at: data.created_at, seq: data.seq });
      setPeerStatus(true);
    } else if (data.type === 'typing' && data.from_public_id === withUser) {
      showTyping();
    } else if (data.type === 'dm_read' && data.reader_public_id === withUser) {
      // The other person just opened/viewed this thread - flip every message
      // I've sent them so far to "seen" right now, instead of only updating
      // the next time I myself leave and re-open the conversation.
      document.querySelectorAll('#chat-messages .msg-bubble.mine .msg-seen').forEach((el) => {
        el.classList.add('is-read');
        el.textContent = '✓✓';
      });
    }
  };

  socket.onerror = (err) => console.error('Chat socket error - will retry:', err);

  socket.onclose = () => {
    // Reconnect after a short delay if the socket drops (server restart, network blip,
    // or an expired/invalid auth token - ensure_ws_session() on the next page load
    // issues a fresh one automatically, but an already-open tab needs a reconnect too).
    setTimeout(connectSocket, 3000);
  };
}

fetchNew().then(() => {
  connectSocket();
  setInterval(() => fetchNew(), 3000); // fallback poll - safe no-op if the socket already delivered everything
});

// --- Contact Info panel (third view: List -> Conversation -> Info) ---
const shell = document.querySelector('.messenger-shell');
document.getElementById('open-contact-info')?.addEventListener('click', () => {
  shell?.classList.add('info-open');
});
document.getElementById('close-contact-info')?.addEventListener('click', () => {
  shell?.classList.remove('info-open');
});

document.getElementById('info-follow-btn')?.addEventListener('click', async (e) => {
  const btn = e.currentTarget;
  const following = btn.dataset.following === '1';
  const fd = new FormData();
  fd.set('csrf_token', document.querySelector('input[name="csrf_token"]').value);
  fd.set('user_id', btn.dataset.user);
  fd.set('action', following ? 'unfollow' : 'follow');

  btn.disabled = true;
  try {
    const res = await fetch('/actions/follow.php', { method: 'POST', body: fd, credentials: 'same-origin' });
    const data = await res.json();
    if (data.success) {
      btn.dataset.following = following ? '0' : '1';
      btn.textContent = following ? '+ Follow' : 'Following ✓';
    } else {
      alert(data.error || 'Could not update follow status.');
    }
  } finally {
    btn.disabled = false;
  }
});
