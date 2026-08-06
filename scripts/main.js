function toggleSidebar() {
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('sidebarOverlay');
  if (!sidebar || !overlay) return;
  sidebar.classList.toggle('is-open');
  overlay.classList.toggle('is-visible');
}

document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.support-faq__question, .faq-item__question').forEach(question => {
    question.addEventListener('click', () => {
      const item = question.closest('.support-faq__item, .faq-item');
      if (!item) return;
      const isOpen = item.classList.contains('is-open');
      document.querySelectorAll('.support-faq__item.is-open, .faq-item.is-open').forEach(openItem => openItem.classList.remove('is-open'));
      if (!isOpen) item.classList.add('is-open');
    });
  });

  /* Animations d'entrée décalées sur les cartes des pages */
  document.querySelectorAll('.dashboard__card, .page-header, .dashboard__cta-banner').forEach((card, i) => {
    if (i >= 12 || card.classList.contains('animate-in')) return;
    card.classList.add('animate-in');
    card.style.animationDelay = (i * 0.06) + 's';
  });
});



