document.addEventListener('DOMContentLoaded', () => {
  const input = document.querySelector('#pos-search');
  const cards = Array.from(document.querySelectorAll('.pos-product-card'));
  const empty = document.querySelector('#pos-empty');
  if (!input || !cards.length) return;

  const normalize = (value) => value.toLowerCase().trim();

  const filterProducts = () => {
    const query = normalize(input.value);
    let visibleCount = 0;

    cards.forEach((card) => {
      const name = card.dataset.name || '';
      const match = name.includes(query);
      card.style.display = match ? '' : 'none';
      if (match) visibleCount += 1;
    });

    if (empty) {
      empty.style.display = visibleCount ? 'none' : 'block';
    }
  };

  input.addEventListener('input', filterProducts);
  filterProducts();
});
