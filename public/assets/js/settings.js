document.getElementById('settings-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const form = e.target;
  const msg = document.getElementById('form-message');
  msg.className = 'form-message';
  msg.textContent = '';

  const res = await fetch('/actions/settings_save.php', {
    method: 'POST',
    body: new FormData(form),
    credentials: 'same-origin',
  });
  const data = await res.json();

  if (res.ok && data.success) {
    msg.textContent = 'Saved.';
    msg.style.color = 'var(--color-success)';
  } else {
    msg.textContent = data.error || 'Something went wrong.';
    msg.classList.add('form-message--error');
  }
});
