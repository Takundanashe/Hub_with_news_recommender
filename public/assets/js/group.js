const messagesEl = document.getElementById('chat-messages');
messagesEl.scrollTop = messagesEl.scrollHeight;

let lastSeq = window.LAST_SEQ || 0;
// Seed grouping/date-pill state from whatever was server-rendered, so the
// first live-appended message correctly continues (or breaks) the pattern
// instead of always starting a fresh group.
const renderedBubbles = messagesEl.querySelectorAll('.msg-bubble');
const lastBubble = renderedBubbles[renderedBubbles.length - 1];
let lastRenderedSender = lastBubble ? (lastBubble.classList.contains('mine') ? 'mine' : 'theirs') : null;
let lastRenderedDateKey = null;

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
function dateKey(d) { return `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`; }
function formatDatePill(d) {
  const today = new Date();
  if (dateKey(d) === dateKey(today)) return 'Today';
  const yesterday = new Date(today); yesterday.setDate(today.getDate() - 1);
  if (dateKey(d) === dateKey(yesterday)) return 'Yesterday';
  return d.toLocaleDateString([], { month: 'short', day: 'numeric' });
}

// Server-rendered history has raw UTC strings in data-created (PHP doesn't
// know the viewer's timezone) - format them client-side on load, same as
// live/pushed messages, so both look consistent. Also seed lastRenderedDateKey
// from the last one so a same-day live message doesn't insert a redundant pill.
document.querySelectorAll('.msg-time[data-created]').forEach((el) => {
  const d = toLocalDate(el.dataset.created);
  el.textContent = formatTime(el.dataset.created);
  if (d) lastRenderedDateKey = dateKey(d);
});

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

function appendBubble(name, body, mine = false, createdAt = null, seq = null) {
  if (seq && messagesEl.querySelector(`[data-msg-id="${seq}"]`)) return null; // already rendered (socket + poll both delivered it)
  if (seq && seq > lastSeq) lastSeq = seq;

  const wasNearBottom = isNearBottom();
  maybeInsertDatePill(createdAt);

  const sender = mine ? 'mine' : 'theirs';
  const div = document.createElement('div');
  div.className = 'msg-bubble ' + sender + (sender === lastRenderedSender ? ' grouped' : '');
  lastRenderedSender = sender;
  if (seq) div.dataset.msgId = String(seq);

  if (!mine) {
    const strong = document.createElement('strong');
    strong.style.cssText = 'display:block;font-size:11px;opacity:0.8;';
    strong.textContent = name;
    div.appendChild(strong);
  }
  const bodyEl = document.createElement('div');
  bodyEl.className = 'msg-body';
  bodyEl.textContent = body; // never innerHTML with user content
  div.appendChild(bodyEl);
  const timeEl = document.createElement('span');
  timeEl.className = 'msg-time';
  timeEl.textContent = formatTime(createdAt || new Date().toISOString());
  div.appendChild(timeEl);
  messagesEl.appendChild(div);

  // Keep scroll position: only snap to bottom if the reader was already
  // there - don't yank them down mid-scrollback through history.
  if (wasNearBottom) messagesEl.scrollTop = messagesEl.scrollHeight;
  return div;
}

// GET latest_message_id -> only fetch newer messages -> append -> keep
// scroll position. This is what actually fixes messages not showing up
// without a refresh: the WebSocket push below is a nice-to-have for
// instant delivery, but this poll guarantees delivery either way.
async function pollMessages() {
  try {
    const res = await fetch(`/actions/group_messages_fetch.php?id=${encodeURIComponent(window.GROUP_ID)}&after=${lastSeq}`, { credentials: 'same-origin' });
    if (!res.ok) return;
    const data = await res.json();
    (data.messages || []).forEach((m) => appendBubble(m.name, m.body, m.mine, m.created_at, m.seq));
  } catch (err) {
    // Network hiccup - the next interval retries, nothing to surface to the user.
  }
}

document.getElementById('group-composer-form')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const form = e.target;
  const input = document.getElementById('group-input');
  const body = input.value.trim();
  if (!body) return;
  input.value = '';

  // Render immediately - a network hiccup or a malformed response shouldn't
  // be able to hide a message that's about to be sent.
  const bubble = appendBubble('You', body, true);
  if (bubble) bubble.style.opacity = '0.6';

  const fd = new FormData(form);
  fd.set('body', body);

  try {
    const res = await fetch('/actions/group_message_send.php', { method: 'POST', body: fd, credentials: 'same-origin' });
    const text = await res.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch {
      throw new Error('Unexpected server response: ' + text.slice(0, 200));
    }
    if (!res.ok || !data.success) {
      throw new Error(data.error || 'Message failed to send.');
    }
    if (bubble) {
      bubble.style.opacity = '1';
      if (data.seq) { bubble.dataset.msgId = String(data.seq); lastSeq = Math.max(lastSeq, data.seq); }
    }
    pollMessages(); // pick up anything else new right away
  } catch (err) {
    if (bubble) {
      bubble.style.opacity = '0.5';
      bubble.title = 'Failed to send - tap to retry';
      bubble.style.cursor = 'pointer';
      bubble.onclick = () => { bubble.remove(); input.value = body; document.getElementById('group-input').focus(); };
    }
    console.error(err);
  }
});

document.getElementById('emoji-btn')?.addEventListener('click', () => {
  const input = document.getElementById('group-input');
  if (input) { input.value += '😊'; input.focus(); }
});

function connectSocket() {
  if (!window.WS_TOKEN) return;
  const proto = location.protocol === 'https:' ? 'wss' : 'ws';
  const socket = new WebSocket(`${proto}://${location.host}/ws?token=${encodeURIComponent(window.WS_TOKEN)}`);
  socket.onmessage = (event) => {
    const data = JSON.parse(event.data);
    if (data.type === 'group' && data.group_id === window.GROUP_ID) {
      appendBubble(data.from, data.body, false, data.created_at, data.seq);
    }
  };
  socket.onerror = (err) => console.error('Group socket error - will retry:', err);
  socket.onclose = () => setTimeout(connectSocket, 3000);
}

connectSocket();
setInterval(pollMessages, 3000); // fallback poll - safe no-op if the socket already delivered everything

// --- Group Info panel (third view: Groups list -> Conversation -> Info) ---
const groupShell = document.querySelector('.messenger-shell');
document.getElementById('open-contact-info')?.addEventListener('click', () => {
  groupShell?.classList.add('info-open');
});
document.getElementById('close-contact-info')?.addEventListener('click', () => {
  groupShell?.classList.remove('info-open');
});
