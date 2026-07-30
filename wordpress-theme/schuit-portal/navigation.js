document.addEventListener('DOMContentLoaded', () => {
  const header = document.querySelector('.site-header');
  const toggle = document.querySelector('.site-menu-toggle');
  const nav = document.querySelector('#site-navigation');

  if (!header || !toggle || !nav) {
    return;
  }

  const setOpen = (isOpen) => {
    header.classList.toggle('site-header--menu-open', isOpen);
    toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
  };

  header.classList.add('site-header--js');
  setOpen(false);

  toggle.addEventListener('click', () => {
    setOpen(toggle.getAttribute('aria-expanded') !== 'true');
  });

  nav.addEventListener('click', (event) => {
    if (event.target instanceof Element && event.target.closest('a')) {
      setOpen(false);
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      setOpen(false);
    }
  });
});
