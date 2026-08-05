const form = document.getElementById('signup-form');
const errorBox = document.getElementById('auth-error');
const submitBtn = document.getElementById('submit-btn');
const submitLabel = document.getElementById('submit-label');

function showError(msg) {
  errorBox.textContent = msg;
  errorBox.classList.add('is-visible');
}
function clearError() {
  errorBox.textContent = '';
  errorBox.classList.remove('is-visible');
}

document.querySelectorAll('.toggle-password').forEach((btn) => {
  btn.addEventListener('click', (e) => {
    const input = e.currentTarget.previousElementSibling;
    const showing = input.type === 'text';
    input.type = showing ? 'password' : 'text';
    e.currentTarget.textContent = showing ? '👁' : '🙈';
  });
});

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  clearError();

  const password = document.getElementById('password').value;
  const confirm = document.getElementById('password_confirm').value;
  if (password !== confirm) {
    showError("Passwords don't match.");
    return;
  }

  submitBtn.disabled = true;
  const spinner = document.createElement('span');
  spinner.className = 'auth-v2-spinner';
  submitBtn.prepend(spinner);
  submitLabel.textContent = 'Creating account...';

  try {
    const res = await fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin' });
    const text = await res.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch {
      throw new Error('Unexpected response from the server. Please try again.');
    }

    if (res.ok && data.success) {
      form.classList.add('auth-v2-fadeout');
      setTimeout(() => { window.location.href = data.redirect; }, 220);
      return;
    }
    showError(data.error || 'Something went wrong. Please try again.');
  } catch (err) {
    showError(err.message || 'Something went wrong. Please try again.');
  } finally {
    spinner.remove();
    submitLabel.textContent = 'Create account';
    submitBtn.disabled = false;
  }
});
