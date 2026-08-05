const dialog = document.getElementById('new-group-dialog');
document.getElementById('new-group-btn')?.addEventListener('click', () => dialog.showModal());
document.getElementById('cancel-group-btn')?.addEventListener('click', () => dialog.close());

document.getElementById('new-group-form')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const form = e.target;
  const msg = document.getElementById('form-message');
  msg.className = 'form-message';

  const res = await fetch('/actions/group_create.php', { method: 'POST', body: new FormData(form), credentials: 'same-origin' });
  const data = await res.json();

  if (res.ok && data.success) {
    window.location.href = `/group.php?id=${encodeURIComponent(data.group_id)}`;
  } else {
    msg.textContent = data.error || 'Something went wrong.';
    msg.classList.add('form-message--error');
  }
});

document.querySelectorAll('.join-group-btn').forEach((btn) => {
  btn.addEventListener('click', async () => {
    const fd = new FormData();
    fd.set('csrf_token', document.querySelector('input[name="csrf_token"]').value);
    fd.set('group_id', btn.dataset.group);
    const res = await fetch('/actions/group_join.php', { method: 'POST', body: fd, credentials: 'same-origin' });
    const data = await res.json();
    if (data.success) location.reload();
    else alert(data.error || 'Could not join group.');
  });
});
