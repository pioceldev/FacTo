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
            <button type="button" class="btn btn-outline-secondary" data-facto-cancel ${cancelText ? '' : 'style="display:none;"'}>${cancelText}</button>
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

  /* Ouverture robuste de modale : Bootstrap si disponible, sinon fallback manuel.
     Garantit que les boutons « Nouveau / Ajouter » affichent toujours leur modale. */
  window.openModal = function (id) {
    const el = document.getElementById(id);
    if (!el) return;
    if (window.bootstrap && window.bootstrap.Modal) {
      try {
        bootstrap.Modal.getOrCreateInstance(el).show();
        return;
      } catch (e) { /* fallback ci-dessous */ }
    }
    el.classList.add('show');
    el.style.display = 'block';
    el.setAttribute('aria-hidden', 'false');
    document.body.classList.add('modal-open');
    if (!document.querySelector('.modal-backdrop')) {
      const backdrop = document.createElement('div');
      backdrop.className = 'modal-backdrop fade show';
      document.body.appendChild(backdrop);
    }
  };

  /* Fermeture robuste de modale (Bootstrap si disponible, sinon fallback manuel) */
  window.closeModal = function (id) {
    const el = document.getElementById(id);
    if (!el) return;
    if (window.bootstrap && window.bootstrap.Modal) {
      try {
        bootstrap.Modal.getOrCreateInstance(el).hide();
        return;
      } catch (e) { /* fallback ci-dessous */ }
    }
    el.classList.remove('show');
    el.style.display = 'none';
    el.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('modal-open');
    document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
  };

  /* Au clic sur un bouton data-bs-target, ouvre la modale même si le script
     de la page a échoué à se charger (action garantie sur les boutons d'ajout). */
  document.addEventListener('click', function (e) {
    const trigger = e.target.closest('[data-facto-modal]');
    if (trigger) {
      e.preventDefault();
      window.openModal(trigger.getAttribute('data-facto-modal'));
    }
  });

  /* ===== Token CSRF partagé — SOURCE UNIQUE =====
     fetchCsrfToken() récupère le token (et le met en cache) ; csrfToken() le relit.
     Tous les modules l'utilisent pour injecter _csrf dans leurs requêtes de mutation.
     force=true contourne le cache (utilisé pour retenter après un 403 CSRF). */
  let _csrfToken = null;
  let _csrfInflight = null;
  window.fetchCsrfToken = async function (force) {
    if (_csrfToken && !force) return _csrfToken;
    if (_csrfInflight && !force) return _csrfInflight;
    _csrfInflight = (async () => {
      try {
        const r = await fetch('backend/csrf.php?action=token', { credentials: 'same-origin' });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        const d = await r.json();
        if (!d || !d.csrf_token) throw new Error('Réponse inattendue du serveur');
        _csrfToken = d.csrf_token;
      } catch (e) {
        console.error('[FacTo] Impossible de récupérer le token CSRF :', e);
        _csrfToken = null;
      } finally {
        _csrfInflight = null;
      }
      return _csrfToken;
    })();
    return _csrfInflight;
  };
  window.csrfToken = function () { return _csrfToken ?? ''; };
})();
