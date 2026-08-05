// Format timestamps and previews using the shared relative-time helper.
document.querySelectorAll('.contact-time[data-created]').forEach((el) => {
  el.textContent = formatRelativeTime(el.dataset.created);
});

// Live filter as you type - client-side, since the list is already small
// enough to be fully rendered (no need for a server round-trip per keystroke).
const filterInput = document.getElementById('contact-filter');
filterInput?.addEventListener('input', () => {
  const q = filterInput.value.trim().toLowerCase();
  document.querySelectorAll('.contact-row').forEach((row) => {
    const name = row.querySelector('.contact-name')?.textContent.toLowerCase() || '';
    row.style.display = name.includes(q) ? '' : 'none';
  });
});
