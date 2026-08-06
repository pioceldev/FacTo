/* FacTo — petits utilitaires UI dynamiques (toasts, modales, skeletons) */

(function () {
  function ensureToastsContainer() {
    let el = document.querySelector('.facto-toasts');
    if (!el) {
      el = document.createElement('div');
      el.className = 'facto-toasts';
      document.body.appendChild(el);
    }
    return el;
  }

  window.toast = function (message, type = 'success', duration = 4000) {
    const container = ensureToastsContainer();
    const toast = document.createElement('div');
    toast.className = 'facto-toast is-' + (type === 'error' ? 'error' : type === 'info' ? 'info' : 'success');

    const icons = { success: 'bi-check-circle-fill', error: 'bi-x-circle-fill', info: 'bi-info-circle-fill' };
    const icon = document.createElement('span');
    icon.className = 'facto-toast__icon bi ' + (icons[type] || icons.info);

    const body = document.createElement('span');
    body.className = 'facto-toast__body';
    body.textContent = message;

    const close = document.createElement('button');
    close.className = 'facto-toast__close bi bi-x-lg';
    close.setAttribute('aria-label', 'Fermer');
    close.addEventListener('click', () => dismiss());

    toast.appendChild(icon);
    toast.appendChild(body);
    toast.appendChild(close);
    container.appendChild(toast);

    let timer = null;
    function dismiss() {
      toast.classList.add('is-leaving');
      setTimeout(() => toast.remove(), 300);
      if (timer) clearTimeout(timer);
    }
    timer = setTimeout(dismiss, duration);
  };

  /* Modale de confirmation asynchrone : await uiConfirm({...}) => true/false */
  window.uiConfirm = function (options) {
    const { title = 'Confirmer', text = '', confirmText = 'Confirmer', cancelText = 'Annuler', icon = 'bi-question-circle', danger = false } = options || {};

    return new Promise((resolve) => {
      const backdrop = document.createElement('div');
      backdrop.className = 'facto-modal-backdrop';
      backdrop.innerHTML = `
        <div class="facto-modal" role="dialog" aria-modal="true">
          <div class="facto-modal__icon" style="${danger ? 'background:rgba(220,38,38,.12);color:#dc2626;' : ''}"><i class="bi ${icon}"></i></div>
          <div class="facto-modal__title"></div>
          <div class="facto-modal__text"></div>
          <div class="facto-modal__actions">
            <button type="button" class="btn btn-outline-secondary" data-facto-cancel>${cancelText}</button>
            <button type="button" class="btn ${danger ? 'btn-danger' : 'btn-primary'}" data-facto-ok>${confirmText}</button>
          </div>
        </div>`;
      backdrop.querySelector('.facto-modal__title').textContent = title;
      backdrop.querySelector('.facto-modal__text').textContent = text;

      function done(value) {
        backdrop.remove();
        resolve(value);
      }
      backdrop.addEventListener('click', (e) => {
        if (e.target === backdrop) done(false);
      });
      backdrop.querySelector('[data-facto-cancel]').addEventListener('click', () => done(false));
      backdrop.querySelector('[data-facto-ok]').addEventListener('click', () => done(true));

      document.body.appendChild(backdrop);
    });
  };

  /* Remplit un conteneur avec des cartes skeleton */
  window.showSkeleton = function (container, count = 3) {
    if (!container) return;
    let html = '';
    for (let i = 0; i < count; i++) {
      html += '<div class="skeleton skeleton-card"></div>';
    }
    container.innerHTML = html;
  };

  /* Renvoie la classe d'animation d'entrée uniquement au premier rendu du conteneur
     (pour ne pas rejouer l'animation à chaque filtre/refresh) */
  window.onceAnimated = function (container) {
    if (!container) return '';
    const animate = container.dataset.rendered ? '' : ' animate-in';
    container.dataset.rendered = '1';
    return animate;
  };
})();
