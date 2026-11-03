document.querySelectorAll('[data-filter]').forEach(button => {
  button.addEventListener('click', () => {
    document.querySelectorAll('[data-filter]').forEach(item => {
      const active = item === button;
      item.classList.toggle('active', active);
      item.setAttribute('aria-pressed', String(active));
    });
    document.querySelectorAll('[data-category]').forEach(item => {
      item.hidden = button.dataset.filter !== 'all' && item.dataset.category !== button.dataset.filter;
    });
  });
});

const packageSelect = document.querySelector('#booking-package');
if (packageSelect) {
  const slotSelect = document.querySelector('#booking-slot');
  const slotLabel = document.querySelector('#booking-slot-label');
  const note = document.querySelector('#booking-note');
  const availability = document.querySelector('#booking-availability');
  const update = () => {
    const individual = packageSelect.selectedOptions[0]?.dataset.individual === '1';
    let available = 0;
    for (const option of slotSelect.options) {
      if (!option.value) continue;
      const match = option.dataset.package === packageSelect.value;
      option.hidden = !match;
      option.disabled = !match;
      if (match) available++;
      if (!match && option.selected) slotSelect.value = '';
    }
    slotLabel.hidden = individual;
    slotSelect.disabled = individual || !packageSelect.value || !available;
    slotSelect.required = !individual;
    if (individual) { slotSelect.value = ''; if (!packageSelect.form.elements.type.value) packageSelect.form.elements.type.value = 'Brand'; }
    note.required = individual;
    note.minLength = individual ? 5 : 0;
    availability.textContent = individual ? 'Termín a rozsah domluvíme individuálně. Napište nám svou představu.' : !packageSelect.value ? 'Nejdříve vyberte balíček.' : available ? 'Vyberte některý z volných termínů pro tento balíček.' : 'Pro tento balíček momentálně nejsou vypsané volné termíny.';
    packageSelect.form.querySelector('button[type="submit"]').disabled = !individual && (!packageSelect.value || !available);
  };
  packageSelect.addEventListener('change', update);
  update();
}
