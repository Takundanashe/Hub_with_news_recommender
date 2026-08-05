const form = document.getElementById('login-form');
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

document.getElementById('toggle-password')?.addEventListener('click', (e) => {
  const input = document.getElementById('password');
  const showing = input.type === 'text';
  input.type = showing ? 'password' : 'text';
  e.currentTarget.textContent = showing ? '👁' : '🙈';
});

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  clearError();
  submitBtn.disabled = true;
  submitLabel.innerHTML = '';
  const spinner = document.createElement('span');
  spinner.className = 'auth-v2-spinner';
  submitBtn.prepend(spinner);
  submitLabel.textContent = 'Signing in...';

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
    submitLabel.textContent = 'Login';
    submitBtn.disabled = false;
  }
});
