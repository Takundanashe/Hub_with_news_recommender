(function () {
  let sheetEl, backdropEl;

  function ensureSheet() {
    if (sheetEl) return;
    backdropEl = document.createElement('div');
    backdropEl.className = 'sheet-backdrop';
    sheetEl = document.createElement('div');
    sheetEl.className = 'bottom-sheet glass-panel';
    document.body.appendChild(backdropEl);
    document.body.appendChild(sheetEl);
    backdropEl.addEventListener('click', closeSheet);
  }

  function openSheet(html) {
    ensureSheet();
    sheetEl.innerHTML = '';
    const handle = document.createElement('div');
    handle.className = 'sheet-handle';
    sheetEl.appendChild(handle);
    sheetEl.appendChild(html);
    requestAnimationFrame(() => {
      backdropEl.classList.add('is-visible');
      sheetEl.classList.add('is-open');
    });
  }

  function closeSheet() {
    if (!sheetEl) return;
    sheetEl.classList.remove('is-open');
    backdropEl.classList.remove('is-visible');
  }

  function csrf() {
    return document.querySelector('input[name="csrf_token"]')?.value || window.CSRF_TOKEN || '';
  }

  async function postForm(url, fields) {
    const fd = new FormData();
    fd.set('csrf_token', csrf());
    Object.entries(fields).forEach(([k, v]) => fd.set(k, v));
    const res = await fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' });
    return res.json();
  }

  function buildHeader(name, avatar) {
    const header = document.createElement('div');
    header.className = 'sheet-header';
    const img = document.createElement('img');
    img.src = '/uploads/' + avatar;
    const strong = document.createElement('strong');
    strong.textContent = name;
    header.appendChild(img);
    header.appendChild(strong);
    return header;
  }

  window.openUserActionSheet = async function (publicId) {
    const res = await fetch(`/actions/user_state.php?user=${encodeURIComponent(publicId)}`, { credentials: 'same-origin' });
    const state = await res.json();
    if (!res.ok) return;

    const wrap = document.createElement('div');
    wrap.appendChild(buildHeader(state.name, state.avatar));

    if (state.is_self) {
      openSheet(wrap);
      return;
    }

    const actions = document.createElement('div');
    actions.className = 'sheet-actions';

    const followBtn = document.createElement('button');
    followBtn.textContent = state.is_following ? 'Unfollow' : 'Follow';
    followBtn.addEventListener('click', async () => {
      const data = await postForm('/actions/follow.php', {
        user_id: publicId,
        action: state.is_following ? 'unfollow' : 'follow',
      });
      if (data.success) {
        closeSheet();
        window.openUserActionSheet(publicId); // reopen with fresh state
      }
    });
    actions.appendChild(followBtn);

    if (state.can_message) {
      const msgBtn = document.createElement('a');
      msgBtn.href = `/chat.php?with=${encodeURIComponent(publicId)}`;
      msgBtn.textContent = 'Message';
      actions.appendChild(msgBtn);
    }

    wrap.appendChild(actions);
    openSheet(wrap);
  };

  window.openGroupActionSheet = async function (publicId) {
    const res = await fetch(`/actions/group_state.php?group=${encodeURIComponent(publicId)}`, { credentials: 'same-origin' });
    const state = await res.json();
    if (!res.ok) return;

    const wrap = document.createElement('div');
    wrap.appendChild(buildHeader(state.name, state.avatar || 'default_group.png'));

    const actions = document.createElement('div');
    actions.className = 'sheet-actions';

    if (state.is_member) {
      const openBtn = document.createElement('a');
      openBtn.href = `/group.php?id=${encodeURIComponent(publicId)}`;
      openBtn.textContent = 'Open group';
      actions.appendChild(openBtn);
    } else if (state.privacy === 'public') {
      const joinBtn = document.createElement('button');
      joinBtn.textContent = 'Join group';
      joinBtn.addEventListener('click', async () => {
        const data = await postForm('/actions/group_join.php', { group_id: publicId });
        if (data.success) window.location.href = `/group.php?id=${encodeURIComponent(publicId)}`;
      });
      actions.appendChild(joinBtn);
    } else {
      const note = document.createElement('p');
      note.style.cssText = 'text-align:center; color:var(--color-ink-soft); font-size:14px;';
      note.textContent = 'This group is invite-only.';
      actions.appendChild(note);
    }

    wrap.appendChild(actions);
    openSheet(wrap);
  };

  window.closeActionSheet = closeSheet;

  // Delegated listeners - any [data-user]/.author-btn or [data-group]/.group-btn
  // anywhere on the page works automatically, no per-page JS needed.
  document.addEventListener('click', (e) => {
    const userBtn = e.target.closest('.author-btn[data-user]');
    if (userBtn) { window.openUserActionSheet(userBtn.dataset.user); return; }
    const groupBtn = e.target.closest('.group-btn[data-group]');
    if (groupBtn) { window.openGroupActionSheet(groupBtn.dataset.group); }
  });
})();
