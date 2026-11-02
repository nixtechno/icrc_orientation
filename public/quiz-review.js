// Shows a submitted assessment one question at a time; the numbered map and Previous / Next switch between them.
// Without this script every question stays listed and the numbers jump to each one.
document.querySelectorAll('[data-quiz-review]').forEach((review) => {
  const questions = [...review.querySelectorAll('[data-review-question]')];
  const tiles = [...review.querySelectorAll('[data-review-jump]')];
  const pager = review.querySelector('[data-review-pager]');
  if (!questions.length) return;
  const prev = pager.querySelector('[data-review-prev]');
  const next = pager.querySelector('[data-review-next]');
  const position = pager.querySelector('[data-review-position]');
  let current = 0;

  const show = (index, focus) => {
    current = Math.max(0, Math.min(questions.length - 1, index));
    questions.forEach((question, i) => { question.hidden = i !== current; });
    tiles.forEach((tile, i) => tile.toggleAttribute('aria-current', i === current));
    prev.disabled = current === 0;
    next.disabled = current === questions.length - 1;
    position.textContent = `${current + 1} of ${questions.length}`;
    if (focus) questions[current].focus({ preventScroll: true });
  };

  review.classList.add('is-paged');
  pager.hidden = false;
  tiles.forEach((tile, i) => tile.addEventListener('click', (event) => {
    event.preventDefault();
    show(i, true);
    if (window.matchMedia('(max-width: 860px)').matches) questions[i].scrollIntoView({ behavior: 'smooth', block: 'start' });
  }));
  prev.addEventListener('click', () => show(current - 1, true));
  next.addEventListener('click', () => show(current + 1, true));
  review.addEventListener('keydown', (event) => {
    if (event.target.closest('input, textarea, select')) return;
    if (event.key === 'ArrowLeft') show(current - 1, true);
    if (event.key === 'ArrowRight') show(current + 1, true);
  });

  const linked = questions.findIndex((question) => `#${question.id}` === location.hash);
  show(linked < 0 ? 0 : linked, false);
});
