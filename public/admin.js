document.querySelectorAll('input[name="is_individual"]').forEach(toggle => {
  const duration = toggle.form.querySelector('input[name="duration_minutes"]');
  if (!duration) return;
  const update = () => {
    duration.closest('label').hidden = toggle.checked;
    duration.disabled = toggle.checked;
    duration.required = !toggle.checked;
  };
  toggle.addEventListener('change', update);
  update();
});
