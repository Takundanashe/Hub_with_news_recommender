if (window.QRCode && window.MONEY_ID) {
  QRCode.toCanvas(window.MONEY_ID, { width: 160, margin: 1 }, (err, canvas) => {
    if (!err) document.getElementById('qr-code').appendChild(canvas);
  });
}

document.getElementById('qr-toggle')?.addEventListener('click', () => {
  const el = document.getElementById('qr-code');
  el.style.display = el.style.display === 'none' ? 'flex' : 'none';
});

document.getElementById('send-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const form = e.target;
  const msg = document.getElementById('form-message');
  msg.className = 'form-message';
  const btn = form.querySelector('button[type="submit"]');
  btn.disabled = true;

  try {
    const res = await fetch('/actions/wallet_send.php', { method: 'POST', body: new FormData(form), credentials: 'same-origin' });
    const data = await res.json();
    if (res.ok && data.success) {
      location.reload();
    } else {
      msg.textContent = data.error || 'Transfer failed.';
      msg.classList.add('form-message--error');
    }
  } finally {
    btn.disabled = false;
  }
});
