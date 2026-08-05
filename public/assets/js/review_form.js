document.getElementById('review-form')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  const form = e.target;
  const msg = document.getElementById('review-message');
  msg.className = 'form-message';

  const res = await fetch('/actions/listing_review.php', { method: 'POST', body: new FormData(form), credentials: 'same-origin' });
  const data = await res.json();

  if (res.ok && data.success) {
    location.reload();
  } else {
    msg.textContent = data.error || 'Something went wrong.';
    msg.classList.add('form-message--error');
  }
});
