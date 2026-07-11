document.addEventListener('DOMContentLoaded', () => {
  const rangeSelect = document.querySelector('[data-history-range]');
  const customFields = Array.from(document.querySelectorAll('[data-custom-date]'));
  if (!rangeSelect || !customFields.length) return;

  const syncCustomFields = () => {
    const show = rangeSelect.value === 'custom';
    customFields.forEach((field) => {
      field.hidden = !show;
      const input = field.querySelector('input');
      if (input) input.required = show;
    });
  };

  rangeSelect.addEventListener('change', syncCustomFields);
  syncCustomFields();
});
