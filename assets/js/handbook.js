(() => {
  const html = document.documentElement;
  const header = document.querySelector('.book-header');
  new ResizeObserver(() => html.style.setProperty('--book-header-height', `${header.offsetHeight}px`)).observe(header);
  html.dataset.theme = localStorage.getItem('vmange-theme') || 'light';
  document.getElementById('book-theme')?.addEventListener('click', () => {
    html.dataset.theme = html.dataset.theme === 'dark' ? 'light' : 'dark';
    localStorage.setItem('vmange-theme', html.dataset.theme);
  });
  document.getElementById('book-menu')?.addEventListener('click', (event) => {
    const opened = document.body.classList.toggle('chapters-open');
    event.currentTarget.setAttribute('aria-expanded', String(opened));
  });
  document.getElementById('book-print')?.addEventListener('click', () => window.print());
  let chapters;
  const input = document.getElementById('docs-search');
  const results = document.getElementById('docs-results');
  input?.addEventListener('input', async () => {
    try {
      if (!chapters) {
        const response = await fetch('docs.php?search=1', {headers:{Accept:'application/json'}});
        if (!response.ok) throw new Error('Search unavailable. Sign in again if your session expired.');
        chapters = await response.json();
      }
      const term = input.value.trim().toLowerCase();
      results.replaceChildren();
      if (!term) return;
      const matches = chapters.filter(chapter => `${chapter.title} ${chapter.text}`.toLowerCase().includes(term));
      if (!matches.length) results.textContent = 'No matching chapters.';
      for (const chapter of matches) {
        const link = document.createElement('a');
        link.href = `docs.php?page=${encodeURIComponent(chapter.slug)}`;
        link.textContent = chapter.title;
        results.append(link);
      }
    } catch (error) { results.textContent = error.message; }
  });
})();
