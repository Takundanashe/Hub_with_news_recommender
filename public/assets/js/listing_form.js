document.getElementById('listing-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const form = e.target;
  const msg = document.getElementById('form-message');
  msg.className = 'form-message';
  const btn = form.querySelector('button[type="submit"]');
  btn.disabled = true;

  try {
    const res = await fetch('/actions/listing_create.php', { method: 'POST', body: new FormData(form), credentials: 'same-origin' });
    const data = await res.json();
    if (res.ok && data.success) {
      window.location.href = `/market/detail.php?id=${encodeURIComponent(data.listing_id)}`;
    } else {
      msg.textContent = data.error || 'Something went wrong.';
      msg.classList.add('form-message--error');
    }
  } finally {
    btn.disabled = false;
  }
});
