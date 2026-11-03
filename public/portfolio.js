const menuToggle = document.querySelector('#menu-toggle');
document.querySelectorAll('[data-video]').forEach(button => button.addEventListener('click', () => {
  const iframe = document.createElement('iframe');
  iframe.src = button.dataset.video;
  iframe.title = 'Video z původního portfolia Teryfoto';
  iframe.allowFullscreen = true;
  iframe.allow = 'fullscreen; picture-in-picture';
  button.closest('.portfolio-video').replaceChildren(iframe);
}));
menuToggle?.addEventListener('click', () => {
  const open = document.querySelector('#site-menu').classList.toggle('is-open');
  menuToggle.setAttribute('aria-expanded', String(open));
  menuToggle.textContent = open ? 'Zavřít −' : 'Menu +';
});
const lightbox = document.querySelector('#photo-lightbox');
const photos = [...document.querySelectorAll('[data-photo]')];
let activePhoto = 0;
let previousFocus;
const showPhoto = index => {
  activePhoto = (index + photos.length) % photos.length;
  const photo = photos[activePhoto];
  const image = document.querySelector('#lightbox-image');
  image.src = photo.dataset.photo;
  image.alt = photo.dataset.caption;
  document.querySelector('#lightbox-caption').textContent = `${activePhoto + 1} / ${photos.length} · ${photo.dataset.caption}`;
};
photos.forEach((photo,index) => photo.addEventListener('click', () => {
  previousFocus = photo;
  showPhoto(index);
  lightbox.showModal();
  document.body.classList.add('photo-lightbox-open');
}));
document.querySelector('#lightbox-close')?.addEventListener('click', () => lightbox.close());
document.querySelector('#lightbox-prev')?.addEventListener('click', () => showPhoto(activePhoto - 1));
document.querySelector('#lightbox-next')?.addEventListener('click', () => showPhoto(activePhoto + 1));
lightbox?.addEventListener('close', () => {
  document.body.classList.remove('photo-lightbox-open');
  previousFocus?.focus();
});
lightbox?.addEventListener('keydown', event => {
  if (event.key === 'ArrowLeft') { event.preventDefault(); showPhoto(activePhoto - 1); }
  if (event.key === 'ArrowRight') { event.preventDefault(); showPhoto(activePhoto + 1); }
});
if (!matchMedia('(prefers-reduced-motion: reduce)').matches && 'IntersectionObserver' in window) {
  const observer = new IntersectionObserver(entries => entries.forEach(entry => {
    if (entry.isIntersecting) { entry.target.classList.add('reveal-active'); observer.unobserve(entry.target); }
  }),{threshold:.08});
  document.querySelectorAll('.category-tile,.story-photo,.package-card').forEach(node => observer.observe(node));
}
