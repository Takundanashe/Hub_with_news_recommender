const input = document.getElementById('search-input');
const resultsEl = document.getElementById('search-results');
let activeCategory = 'all';
let debounceTimer = null;

document.querySelectorAll('.search-tab').forEach((tab) => {
  tab.addEventListener('click', () => {
    document.querySelectorAll('.search-tab').forEach((t) => t.classList.remove('is-active'));
    tab.classList.add('is-active');
    activeCategory = tab.dataset.cat;
    runSearch();
  });
});

input.addEventListener('input', () => {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(runSearch, 250);
});

async function runSearch() {
  const q = input.value.trim();
  if (!q) {
    resultsEl.innerHTML = '';
    const p = document.createElement('p');
    p.style.cssText = 'padding: 16px; color: var(--color-ink-soft); font-size:14px;';
    p.textContent = 'Start typing to search.';
    resultsEl.appendChild(p);
    return;
  }

  const res = await fetch(`/actions/search.php?q=${encodeURIComponent(q)}&category=${activeCategory}`, { credentials: 'same-origin' });
  const data = await res.json();
  renderResults(data.results || []);
}

function renderResults(results) {
  resultsEl.innerHTML = '';
  if (!results.length) {
    const p = document.createElement('p');
    p.style.cssText = 'padding: 16px; color: var(--color-ink-soft); font-size:14px;';
    p.textContent = 'No results.';
    resultsEl.appendChild(p);
    return;
  }

  results.forEach((r) => {
    const row = document.createElement('div');
    row.className = 'search-result-row';

    const img = document.createElement('img');
    img.src = `/uploads/${r.image}`;
    if (r.type === 'goods') img.classList.add('square');
    row.appendChild(img);

    const text = document.createElement('div');
    text.style.flex = '1';
    const title = document.createElement('div');
    title.style.fontWeight = '600';
    title.textContent = r.title;
    const subtitle = document.createElement('div');
    subtitle.style.cssText = 'font-size:12px; color:var(--color-ink-soft);';
    subtitle.textContent = r.subtitle;
    text.appendChild(title);
    text.appendChild(subtitle);
    row.appendChild(text);

    row.addEventListener('click', () => {
      if (r.type === 'user') window.openUserActionSheet(r.id);
      else if (r.type === 'group') window.openGroupActionSheet(r.id);
      else if (r.type === 'goods') window.location.href = `/market/detail.php?id=${encodeURIComponent(r.id)}`;
    });

    resultsEl.appendChild(row);
  });
}
