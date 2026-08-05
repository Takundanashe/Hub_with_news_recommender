/* ---------- FAB / expandable composer ---------- */

const fab = document.getElementById('composer-fab');
const panel = document.getElementById('composer-panel');
const closeBtn = document.getElementById('composer-close');
const form = document.getElementById('news-form');
const bodyField = document.getElementById('composer-body');
const imageInput = document.getElementById('composer-image-input');
const imagePreview = document.getElementById('composer-image-preview');
const imagePreviewImg = document.getElementById('composer-image-preview-img');
const imageRemoveBtn = document.getElementById('composer-image-remove');
const submitBtn = document.getElementById('composer-submit');
const submitLabel = submitBtn.querySelector('.composer-submit-label');
const submitSpinner = submitBtn.querySelector('.composer-submit-spinner');
const msg = document.getElementById('form-message');
const feedList = document.getElementById('feed-list');

function openComposer() {
  panel.classList.add('is-open');
  panel.setAttribute('aria-hidden', 'false');
  fab.setAttribute('aria-expanded', 'true');
  bodyField.focus();
}

function closeComposer() {
  panel.classList.remove('is-open');
  panel.setAttribute('aria-hidden', 'true');
  fab.setAttribute('aria-expanded', 'false');
}

fab.addEventListener('click', () => {
  if (panel.classList.contains('is-open')) closeComposer();
  else openComposer();
});
closeBtn.addEventListener('click', closeComposer);

document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' && panel.classList.contains('is-open')) closeComposer();
});

/* ---------- Image selection -> inline preview ---------- */

imageInput.addEventListener('change', () => {
  const file = imageInput.files && imageInput.files[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = () => {
    imagePreviewImg.src = reader.result;
    imagePreview.hidden = false;
  };
  reader.readAsDataURL(file);
});

imageRemoveBtn.addEventListener('click', () => {
  imageInput.value = '';
  imagePreviewImg.src = '';
  imagePreview.hidden = true;
});

function resetComposer() {
  form.reset();
  imagePreviewImg.src = '';
  imagePreview.hidden = true;
  msg.className = 'form-message';
  msg.textContent = '';
}

/* ---------- Build a feed <article> from what the composer already
   knows (avatar/name from the panel header, text/image/toggle from
   the form) plus the post_id the server just returned. Lets us
   insert the new post without a full reload. The feed card is
   summary-only now - comments live on their own page. ---------- */

function buildFeedItem(postId, csrfToken) {
  const avatarSrc = document.querySelector('.composer-panel-header img').src;
  const name = document.querySelector('.composer-name').textContent;
  const body = bodyField.value;
  const commentsEnabled = form.querySelector('input[name="comments_enabled"]').checked;
  const imgSrc = imagePreview.hidden ? null : imagePreviewImg.src;

  const article = document.createElement('article');
  article.className = 'card feed-item';
  article.dataset.post = postId;

  const header = document.createElement('div');
  header.className = 'feed-item-header';
  header.innerHTML = `
    <button class="author-btn">
      <img src="${avatarSrc}" alt="">
      <div>
        <div class="meta-name"></div>
        <div class="meta-time">Just now</div>
      </div>
    </button>`;
  header.querySelector('.meta-name').textContent = name;
  article.appendChild(header);

  const p = document.createElement('p');
  p.style.whiteSpace = 'pre-wrap';
  p.textContent = body;
  article.appendChild(p);

  if (imgSrc) {
    const img = document.createElement('img');
    img.src = imgSrc;
    img.alt = '';
    img.style.borderRadius = 'var(--radius-md)';
    img.style.marginTop = '8px';
    article.appendChild(img);
  }

  const actions = document.createElement('div');
  actions.className = 'feed-actions';
  actions.innerHTML = `
    <button class="react-btn" data-reaction="like">👍 <span class="like-count">0</span></button>
    <button class="react-btn" data-reaction="dislike">👎 <span class="dislike-count">0</span></button>
    <button class="echo-btn">🔁 Echo <span class="echo-count">0</span></button>
    ${commentsEnabled
      ? `<a class="comments-toggle" href="/news/comments.php?post=${postId}">💬 <span class="comment-count">0</span> comments</a>`
      : '<span class="comments-toggle comments-toggle--disabled">Comments off</span>'}`;
  article.appendChild(actions);

  return article;
}

/* ---------- Submit ---------- */

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  msg.className = 'form-message';
  msg.textContent = '';

  submitBtn.disabled = true;
  submitLabel.hidden = true;
  submitSpinner.hidden = false;

  const csrfToken = form.querySelector('input[name="csrf_token"]').value;

  try {
    const res = await fetch('/actions/news_create.php', { method: 'POST', body: new FormData(form), credentials: 'same-origin' });
    const data = await res.json();

    if (res.ok && data.success) {
      const article = buildFeedItem(data.post_id, csrfToken);
      feedList.insertAdjacentElement('afterbegin', article);
      bindArticle(article);

      closeComposer();
      resetComposer();
    } else {
      msg.textContent = data.error || 'Something went wrong.';
      msg.classList.add('form-message--error');
    }
  } catch {
    msg.textContent = 'Something went wrong. Please try again.';
    msg.classList.add('form-message--error');
  } finally {
    submitBtn.disabled = false;
    submitLabel.hidden = false;
    submitSpinner.hidden = true;
  }
});

/* ---------- Per-article behavior - bound at load for server-rendered
   posts, and again for each post inserted after a successful post. ---------- */

function bindArticle(article) {
  article.querySelectorAll('.react-btn').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const postId = article.dataset.post;
      const csrf = article.querySelector('input[name="csrf_token"]')?.value
        || document.querySelector('input[name="csrf_token"]').value;

      const fd = new FormData();
      fd.set('csrf_token', csrf);
      fd.set('post_id', postId);
      fd.set('reaction', btn.dataset.reaction);

      const res = await fetch('/actions/news_react.php', { method: 'POST', body: fd, credentials: 'same-origin' });
      const data = await res.json();
      if (!data.success) return;

      article.querySelectorAll('.react-btn').forEach((b) => b.classList.remove('active'));
      article.querySelector('.like-count').textContent = data.likes;
      article.querySelector('.dislike-count').textContent = data.dislikes;
      btn.classList.toggle('active');
    });
  });

  article.querySelectorAll('.echo-btn').forEach((btn) => {
    btn.addEventListener('click', async () => {
      if (btn.disabled) return; // already echoed - server also enforces this, this just avoids a wasted request
      const postId = article.dataset.post;
      const csrf = document.querySelector('input[name="csrf_token"]').value;

      const fd = new FormData();
      fd.set('csrf_token', csrf);
      fd.set('post_id', postId);

      const res = await fetch('/actions/news_echo.php', { method: 'POST', body: fd, credentials: 'same-origin' });
      const data = await res.json();
      if (data.success) {
        btn.classList.add('active');
        btn.disabled = true; // echo is one-time per post, not a toggle
        btn.querySelector('.echo-count').textContent = data.echoes; // real server count, not a local guess
      }
    });
  });

  article.querySelectorAll('.author-btn').forEach((btn) => {
    if (!btn.dataset.user) return; // optimistically-inserted post has no author page yet
    btn.addEventListener('click', () => window.openUserActionSheet(btn.dataset.user));
  });
}

document.querySelectorAll('.feed-item').forEach(bindArticle);

/* ---------- Live updates: reactions, echoes, and comment COUNT only -
   the comments themselves live on their own page now, so the feed just
   needs to keep its badge current, not render each comment. Public feed
   content has no fixed recipient list, so this uses the broadcastAll
   channel rather than notifyUser/broadcastToGroup. ---------- */

function connectNewsSocket() {
  if (!window.WS_TOKEN) return;
  const proto = location.protocol === 'https:' ? 'wss' : 'ws';
  const socket = new WebSocket(`${proto}://${location.host}/ws?token=${encodeURIComponent(window.WS_TOKEN)}`);

  socket.onmessage = (event) => {
    const data = JSON.parse(event.data);
    const article = data.post_id ? document.querySelector(`.feed-item[data-post="${data.post_id}"]`) : null;
    if (!article) return;

    if (data.type === 'news_comment') {
      const countEl = article.querySelector('.comment-count');
      if (countEl && typeof data.comment_count === 'number') countEl.textContent = data.comment_count;
    } else if (data.type === 'news_reaction') {
      article.querySelector('.like-count').textContent = data.likes;
      article.querySelector('.dislike-count').textContent = data.dislikes;
    } else if (data.type === 'news_echo') {
      article.querySelector('.echo-count').textContent = data.echoes;
    }
  };

  socket.onerror = (err) => console.error('News feed socket error - will retry:', err);
  socket.onclose = () => setTimeout(connectNewsSocket, 3000);
}
connectNewsSocket();
