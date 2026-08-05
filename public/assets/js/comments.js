const thread = document.getElementById('comment-thread');
const postContext = document.querySelector('.comment-post-context');
const csrfToken = document.querySelector('input[name="csrf_token"]').value;
const MAX_VISUAL_DEPTH = 3;
const INDENT_PX = 22;

let openReplyCommentId = null;

/* ---------- Post-context reactions / echo (same endpoints as the feed) ---------- */

postContext?.querySelectorAll('.react-btn').forEach((btn) => {
  btn.addEventListener('click', async () => {
    const fd = new FormData();
    fd.set('csrf_token', csrfToken);
    fd.set('post_id', postContext.dataset.post);
    fd.set('reaction', btn.dataset.reaction);

    const res = await fetch('/actions/news_react.php', { method: 'POST', body: fd, credentials: 'same-origin' });
    const data = await res.json();
    if (!data.success) return;

    postContext.querySelectorAll('.react-btn').forEach((b) => b.classList.remove('active'));
    postContext.querySelector('.like-count').textContent = data.likes;
    postContext.querySelector('.dislike-count').textContent = data.dislikes;
    btn.classList.toggle('active');
  });
});

postContext?.querySelector('.echo-btn')?.addEventListener('click', async (e) => {
  const btn = e.currentTarget;
  if (btn.disabled) return;
  const fd = new FormData();
  fd.set('csrf_token', csrfToken);
  fd.set('post_id', postContext.dataset.post);

  const res = await fetch('/actions/news_echo.php', { method: 'POST', body: fd, credentials: 'same-origin' });
  const data = await res.json();
  if (data.success) {
    btn.classList.add('active');
    btn.disabled = true;
    btn.querySelector('.echo-count').textContent = data.echoes;
  }
});

postContext?.querySelectorAll('.author-btn').forEach((btn) => {
  btn.addEventListener('click', () => window.openUserActionSheet(btn.dataset.user));
});

/* ---------- Comment likes (delegated - covers both server-rendered and
   live-inserted comment nodes without rebinding) ---------- */

document.addEventListener('click', async (e) => {
  const btn = e.target.closest('.comment-like-btn');
  if (!btn) return;
  const fd = new FormData();
  fd.set('csrf_token', csrfToken);
  fd.set('comment_id', btn.dataset.comment);
  const res = await fetch('/actions/comment_like.php', { method: 'POST', body: fd, credentials: 'same-origin' });
  const data = await res.json();
  if (data.success) {
    btn.classList.toggle('active', data.liked);
    btn.querySelector('.like-count').textContent = data.count;
  }
});

document.addEventListener('click', (e) => {
  const btn = e.target.closest('.author-btn');
  if (btn && btn.dataset.user) window.openUserActionSheet(btn.dataset.user);
});

/* ---------- Reply toggle + submit ---------- */

function closeOpenReplyForm() {
  if (openReplyCommentId === null) return;
  const slot = document.getElementById(`reply-slot-${openReplyCommentId}`);
  if (slot) slot.innerHTML = '';
  openReplyCommentId = null;
}

function openReplyForm(parentId) {
  if (openReplyCommentId === parentId) { closeOpenReplyForm(); return; } // toggle off if already open here
  closeOpenReplyForm();
  openReplyCommentId = parentId;

  const slot = document.getElementById(`reply-slot-${parentId}`);
  if (!slot) return;

  const form = document.createElement('form');
  form.className = 'comment-reply-form';
  form.innerHTML = `
    <input type="text" placeholder="Write a reply…" maxlength="1000" style="flex:1;">
    <button type="submit" class="btn-secondary btn-sm">Reply</button>`;
  slot.appendChild(form);
  form.querySelector('input').focus();

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const input = form.querySelector('input');
    const body = input.value.trim();
    if (!body) return;

    const fd = new FormData();
    fd.set('csrf_token', csrfToken);
    fd.set('post_id', window.POST_ID);
    fd.set('parent_comment_id', String(parentId));
    fd.set('body', body);

    const res = await fetch('/actions/news_comment.php', { method: 'POST', body: fd, credentials: 'same-origin' });
    const data = await res.json();
    if (data.success) {
      insertCommentIfNew(data);
      updateCommentCount(data.comment_count);
      closeOpenReplyForm();
    } else {
      alert(data.error || 'Reply failed.');
    }
  });
}

document.addEventListener('click', (e) => {
  const hint = e.target.closest('.comment-reply-hint');
  if (!hint) return;
  openReplyForm(parseInt(hint.dataset.replyTo, 10));
});

/* ---------- Top-level comment form ---------- */

document.getElementById('toplevel-comment-form')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const form = e.target;
  const input = form.querySelector('input[name="body"]');
  const body = input.value.trim();
  if (!body) return;

  const fd = new FormData(form);
  fd.set('post_id', window.POST_ID);

  const res = await fetch('/actions/news_comment.php', { method: 'POST', body: fd, credentials: 'same-origin' });
  const data = await res.json();
  if (data.success) {
    insertCommentIfNew(data);
    updateCommentCount(data.comment_count);
    input.value = '';
  } else {
    alert(data.error || 'Comment failed.');
  }
});

/* ---------- Building + inserting a comment node ---------- */

function buildCommentNode(data, depth, parentAuthorName) {
  const visualDepth = Math.min(depth, MAX_VISUAL_DEPTH);
  const node = document.createElement('div');
  node.className = 'comment-node';
  node.dataset.commentId = data.id;
  node.dataset.depth = depth;
  node.style.marginLeft = `${visualDepth * INDENT_PX}px`;

  const img = document.createElement('img');
  img.className = 'comment-avatar';
  img.src = `/uploads/${data.user.avatar}`;
  node.appendChild(img);

  const bodyWrap = document.createElement('div');
  bodyWrap.className = 'comment-card-body';

  const header = document.createElement('div');
  header.className = 'comment-card-header';
  const authorBtn = document.createElement('button');
  authorBtn.className = 'author-btn';
  authorBtn.style.display = 'inline';
  authorBtn.dataset.user = data.user.public_id;
  const nameSpan = document.createElement('span');
  nameSpan.className = 'comment-card-name';
  nameSpan.textContent = data.user.fname;
  authorBtn.appendChild(nameSpan);
  header.appendChild(authorBtn);
  const timeSpan = document.createElement('span');
  timeSpan.className = 'comment-card-time';
  timeSpan.textContent = formatRelativeTime(data.created_at);
  header.appendChild(timeSpan);
  bodyWrap.appendChild(header);

  if (depth > MAX_VISUAL_DEPTH && parentAuthorName) {
    const replyingTo = document.createElement('div');
    replyingTo.className = 'comment-replying-to';
    replyingTo.textContent = `↳ replying to ${parentAuthorName}`;
    bodyWrap.appendChild(replyingTo);
  }

  const textDiv = document.createElement('div');
  textDiv.className = 'comment-card-text';
  textDiv.textContent = data.body; // textContent - never innerHTML with user content
  bodyWrap.appendChild(textDiv);

  const actions = document.createElement('div');
  actions.className = 'comment-card-actions';
  const likeBtn = document.createElement('button');
  likeBtn.className = 'comment-like-btn';
  likeBtn.dataset.comment = data.id;
  likeBtn.innerHTML = '♥ <span class="like-count">0</span>';
  actions.appendChild(likeBtn);
  const replyHint = document.createElement('button');
  replyHint.type = 'button';
  replyHint.className = 'comment-reply-hint';
  replyHint.dataset.replyTo = data.id;
  replyHint.textContent = 'Reply';
  actions.appendChild(replyHint);
  bodyWrap.appendChild(actions);

  const replySlot = document.createElement('div');
  replySlot.className = 'comment-reply-form-slot';
  replySlot.id = `reply-slot-${data.id}`;
  bodyWrap.appendChild(replySlot);

  node.appendChild(bodyWrap);
  return node;
}

/** Depth-first pre-order means every descendant of a comment appears right
 *  after it, before its next sibling - so the correct insertion point for a
 *  new reply is right after the LAST existing node whose depth is greater
 *  than the parent's (i.e. the end of the parent's whole subtree). */
function findInsertionPoint(parentNode) {
  const parentDepth = parseInt(parentNode.dataset.depth, 10);
  let last = parentNode;
  let node = parentNode.nextElementSibling;
  while (node && parseInt(node.dataset.depth, 10) > parentDepth) {
    last = node;
    node = node.nextElementSibling;
  }
  return last;
}

function insertCommentIfNew(data) {
  if (!thread) return;
  if (thread.querySelector(`[data-comment-id="${data.id}"]`)) return; // already rendered - avoids a duplicate when our own broadcast echoes back

  thread.querySelector('.comment-thread-empty')?.remove();

  if (!data.parent_comment_id) {
    thread.appendChild(buildCommentNode(data, 0, null));
    return;
  }

  const parentNode = thread.querySelector(`[data-comment-id="${data.parent_comment_id}"]`);
  if (!parentNode) {
    // Parent isn't in the DOM (e.g. this page loaded before the parent existed,
    // or a WS message arrived out of order) - append at the end rather than
    // silently dropping the reply.
    thread.appendChild(buildCommentNode(data, 1, null));
    return;
  }
  const depth = parseInt(parentNode.dataset.depth, 10) + 1;
  const parentAuthorName = depth > MAX_VISUAL_DEPTH
    ? parentNode.querySelector('.comment-card-name')?.textContent
    : null;
  const insertAfter = findInsertionPoint(parentNode);
  insertAfter.insertAdjacentElement('afterend', buildCommentNode(data, depth, parentAuthorName));
}

function updateCommentCount(count) {
  if (typeof count !== 'number') return;
  const h1 = document.querySelector('.page-header h1');
  if (h1) h1.textContent = `Comments (${count})`;
}

/* ---------- Relative time for server-rendered comments ---------- */

document.querySelectorAll('.comment-card-time[data-created]').forEach((el) => {
  el.textContent = formatRelativeTime(el.dataset.created);
});

/* ---------- Live updates, scoped to this one post ---------- */

function connectCommentsSocket() {
  if (!window.WS_TOKEN) return;
  const proto = location.protocol === 'https:' ? 'wss' : 'ws';
  const socket = new WebSocket(`${proto}://${location.host}/ws?token=${encodeURIComponent(window.WS_TOKEN)}`);

  socket.onmessage = (event) => {
    const data = JSON.parse(event.data);
    if (data.post_id !== window.POST_ID) return;

    if (data.type === 'news_comment') {
      insertCommentIfNew(data);
      updateCommentCount(data.comment_count);
    } else if (data.type === 'news_reaction') {
      postContext.querySelector('.like-count').textContent = data.likes;
      postContext.querySelector('.dislike-count').textContent = data.dislikes;
    } else if (data.type === 'news_echo') {
      postContext.querySelector('.echo-count').textContent = data.echoes;
    } else if (data.type === 'news_comment_like') {
      document.querySelectorAll(`.comment-like-btn[data-comment="${data.comment_id}"] .like-count`).forEach((el) => {
        el.textContent = data.count;
      });
    }
  };

  socket.onerror = (err) => console.error('Comments socket error - will retry:', err);
  socket.onclose = () => setTimeout(connectCommentsSocket, 3000);
}
connectCommentsSocket();
