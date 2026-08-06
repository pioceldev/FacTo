function showFormAlert(container, message, type = 'error') {
  if (!container) return;
  container.textContent = message;
  container.className = 'auth-form__alert auth-form__alert--' + (type === 'success' ? 'success' : 'error');
}

function clearFormAlert(container) {
  if (!container) return;
  container.textContent = '';
  container.className = 'auth-form__alert';
}

function isValidEmail(email) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

function validateAuthForm(form) {
  const action = form.getAttribute('action') || '';
  const alertBox = document.getElementById('authAlert');
  const email = form.querySelector('input[name="email"]')?.value.trim() || '';
  const password = form.querySelector('input[name="password"]')?.value || '';
  console.debug && console.debug('validateAuthForm - email element:', form.querySelector('input[name="email"]'));
  console.debug && console.debug('validateAuthForm - email value:', JSON.stringify(email));

  if (!email) {
    showFormAlert(alertBox, 'L’adresse e-mail est requise.', 'error');
    return false;
  }
  if (!isValidEmail(email)) {
    showFormAlert(alertBox, 'Veuillez saisir une adresse e-mail valide.', 'error');
    return false;
  }

  if (!password) {
    showFormAlert(alertBox, 'Le mot de passe est requis.', 'error');
    return false;
  }
  if (password.length < 8) {
    showFormAlert(alertBox, 'Le mot de passe doit contenir au moins 8 caractères.', 'error');
    return false;
  }

  if (action.includes('action=register')) {
    const company = form.querySelector('input[name="company"]')?.value.trim() || '';
    const name = form.querySelector('input[name="name"]')?.value.trim() || '';
    const phone = form.querySelector('input[name="phone"]')?.value.trim() || '';

    if (!company) {
      showFormAlert(alertBox, 'Le nom de l’entreprise est requis.', 'error');
      return false;
    }
    if (!name) {
      showFormAlert(alertBox, 'Le nom du manager est requis.', 'error');
      return false;
    }
    if (!phone) {
      showFormAlert(alertBox, 'Le numéro de téléphone est requis.', 'error');
      return false;
    }
    if (!/^[0-9+\s.-]{6,20}$/.test(phone)) {
      showFormAlert(alertBox, 'Saisissez un numéro de téléphone valide.', 'error');
      return false;
    }
  }

  clearFormAlert(alertBox);
  return true;
}

function validateSupportForm(form) {
  const alertBox = document.getElementById('supportAlert');
  const email = form.querySelector('input[name="email"]')?.value.trim() || '';
  const message = form.querySelector('textarea[name="message"]')?.value.trim() || '';
  console.debug && console.debug('validateSupportForm - email element:', form.querySelector('input[name="email"]'));
  console.debug && console.debug('validateSupportForm - email value:', JSON.stringify(email));

  if (!email) {
    showFormAlert(alertBox, 'L’adresse e-mail est requise pour le support.', 'error');
    return false;
  }
  if (!isValidEmail(email)) {
    showFormAlert(alertBox, 'Veuillez saisir une adresse e-mail valide.', 'error');
    return false;
  }
  if (!message) {
    showFormAlert(alertBox, 'Veuillez décrire votre demande.', 'error');
    return false;
  }
  if (message.length < 20) {
    showFormAlert(alertBox, 'Votre message doit contenir au moins 20 caractères.', 'error');
    return false;
  }

  clearFormAlert(alertBox);
  return true;
}

document.addEventListener('DOMContentLoaded', () => {
  const currentPage = window.location.pathname.split('/').pop();
  const protectedPages = [
    'dashboard.html',
    'administration.html',
    'clients.html',
    'factures.html',
    'devis.html',
    'paiements.html',
    'depenses.html',
    'rapports.html',
    'produits.html',
    'fournisseurs.html',
    'stock.html',
    'notifications.html',
    'mon-abonnement.html',
    'choix-abonnement.html',
    'support.html',
    'profile.html'
  ];

  if (protectedPages.includes(currentPage)) {
    fetch('backend/session.php', { credentials: 'same-origin' })
      .then(response => response.json())
      .then(data => {
        if (!data.logged_in) {
          window.location.href = 'connexion.html';
        }
      })
      .catch(() => {
        window.location.href = 'connexion.html';
      });
  }

  const authForm = document.querySelector('form.auth-form[action*="backend/auth.php"]:not([action*="action=forgot_password"]):not([action*="action=reset_password"])');
  if (authForm) {
    authForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      clearFormAlert(document.getElementById('authAlert'));

      if (!validateAuthForm(authForm)) {
        return;
      }

      const submitButton = authForm.querySelector('button[type="submit"]');
      if (submitButton) {
        submitButton.disabled = true;
        submitButton.classList.add('disabled');
      }

      const formData = new FormData(authForm);
      const action = authForm.getAttribute('action');

      try {
        const response = await fetch(action, {
          method: 'POST',
          body: formData,
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        const data = await response.json();

        if (!response.ok || data.error) {
          showFormAlert(document.getElementById('authAlert'), data.error || 'Une erreur est survenue. Vérifiez vos informations.', 'error');
        } else {
          const message = data.message || (action.includes('action=login') ? 'Connexion réussie.' : 'Inscription réussie.');
          showFormAlert(document.getElementById('authAlert'), message, 'success');

          if (action.includes('action=login')) {
            window.location.href = 'dashboard.html';
            return;
          }

          if (action.includes('action=register')) {
            setTimeout(() => {
              window.location.href = 'connexion.html';
            }, 1200);
          }
        }
      } catch (error) {
        showFormAlert(document.getElementById('authAlert'), 'Impossible de contacter le serveur. Vérifiez votre connexion.', 'error');
      } finally {
        if (submitButton) {
          submitButton.disabled = false;
          submitButton.classList.remove('disabled');
        }
      }
    });
  }

  const supportForm = document.querySelector('form[action*="backend/support.php"]');
  if (supportForm) {
    supportForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      const alertBox = document.getElementById('supportAlert');
      clearFormAlert(alertBox);

      if (!validateSupportForm(supportForm)) {
        return;
      }

      const submitButton = supportForm.querySelector('button[type="submit"]');
      if (submitButton) {
        submitButton.disabled = true;
        submitButton.classList.add('disabled');
      }

      const formData = new FormData(supportForm);
      const action = supportForm.getAttribute('action');

      try {
        const response = await fetch(action, {
          method: 'POST',
          body: formData,
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await response.json();

        if (!response.ok || data.error) {
          showFormAlert(alertBox, data.error || 'Impossible d’envoyer le ticket.', 'error');
        } else {
          showFormAlert(alertBox, data.message || 'Votre demande a bien été envoyée.', 'success');
          supportForm.reset();
        }
      } catch (error) {
        showFormAlert(alertBox, 'Impossible de contacter le serveur. Vérifiez votre connexion.', 'error');
      } finally {
        if (submitButton) {
          submitButton.disabled = false;
          submitButton.classList.remove('disabled');
        }
      }
    });
  }

  function validateForgotForm(form) {
    const alertBox = document.getElementById('authAlert');
    const email = form.querySelector('input[name="email"]')?.value.trim() || '';
    if (!email) {
      showFormAlert(alertBox, 'Veuillez saisir votre adresse e-mail.', 'error');
      return false;
    }
    if (!isValidEmail(email)) {
      showFormAlert(alertBox, 'Veuillez saisir une adresse e-mail valide.', 'error');
      return false;
    }
    clearFormAlert(alertBox);
    return true;
  }

  function validateResetForm(form) {
    const alertBox = document.getElementById('authAlert');
    const password = form.querySelector('input[name="password"]')?.value || '';
    if (!password) {
      showFormAlert(alertBox, 'Le nouveau mot de passe est requis.', 'error');
      return false;
    }
    if (password.length < 8 || !/\d/.test(password)) {
      showFormAlert(alertBox, 'Le mot de passe doit contenir au moins 8 caractères et un chiffre.', 'error');
      return false;
    }
    clearFormAlert(alertBox);
    return true;
  }

  async function submitPasswordAction(form, onSuccess) {
    const alertBox = document.getElementById('authAlert');
    clearFormAlert(alertBox);
    const submitButton = form.querySelector('button[type="submit"]');
    if (submitButton) {
      submitButton.disabled = true;
      submitButton.classList.add('disabled');
    }

    try {
      const response = await fetch(form.getAttribute('action'), {
        method: 'POST',
        body: new FormData(form),
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const data = await response.json();

      if (!response.ok || data.error) {
        showFormAlert(alertBox, data.error || 'Une erreur est survenue. Réessayez.', 'error');
        return;
      }

      let message = data.message || 'Opération réussie.';
      if (data.reset_link) {
        message += ' Lien de test : ' + data.reset_link;
      }
      showFormAlert(alertBox, message, 'success');
      if (typeof onSuccess === 'function') onSuccess(data);
    } catch (error) {
      showFormAlert(alertBox, 'Impossible de contacter le serveur. Vérifiez votre connexion.', 'error');
    } finally {
      if (submitButton) {
        submitButton.disabled = false;
        submitButton.classList.remove('disabled');
      }
    }
  }

  const forgotForm = document.querySelector('form.auth-form[action*="action=forgot_password"]');
  if (forgotForm) {
    forgotForm.addEventListener('submit', (event) => {
      event.preventDefault();
      if (!validateForgotForm(forgotForm)) return;
      submitPasswordAction(forgotForm);
    });
  }

  const resetForm = document.querySelector('form.auth-form[action*="action=reset_password"]');
  if (resetForm) {
    resetForm.addEventListener('submit', (event) => {
      event.preventDefault();
      if (!validateResetForm(resetForm)) return;
      submitPasswordAction(resetForm, () => {
        setTimeout(() => {
          window.location.href = 'connexion.html';
        }, 1500);
      });
    });
  }

  function setupLogoutLinks() {
    document.querySelectorAll('a.sidebar__item--logout, a[data-logout="true"], #logoutBtn').forEach(link => {
      link.addEventListener('click', async (event) => {
        event.preventDefault();
        try {
          const csrfMeta = document.querySelector('meta[name="csrf-token"]');
          const headers = { 'X-Requested-With': 'XMLHttpRequest' };
          if (csrfMeta?.content) headers['X-CSRF-Token'] = csrfMeta.content;
          // L'action doit être dans l'URL : auth.php lit $_GET['action'].
          // Un corps JSON ne remplit pas $_POST côté PHP => la session ne serait pas détruite.
          await fetch('backend/auth.php?action=logout', {
            method: 'POST',
            credentials: 'same-origin',
            headers,
          });
        } catch (error) {
          // ignore network errors and still redirect
        }
        window.location.href = 'connexion.html';
      });
    });
  }

  setupLogoutLinks();

  /* Badge de notifications global : compteur de non-lus sur toutes les pages */
  function updateNotificationBadge() {
    const badgeEls = document.querySelectorAll('.topbar__badge');
    if (!badgeEls.length) return;
    fetch('backend/notifications.php', { credentials: 'same-origin' })
      .then(response => {
        if (!response.ok) return null;
        return response.json();
      })
      .then(data => {
        if (!data) return;
        const unread = (data.notifications || []).filter(n => !n.is_read).length;
        const label = unread > 9 ? '9+' : unread;
        badgeEls.forEach(badge => {
          badge.textContent = unread > 0 ? label : '';
          badge.style.display = unread > 0 ? 'block' : 'none';
        });
      })
      .catch(() => { /* badge indisponible : on laisse tel quel */ });
  }

  function updateUserUI(data) {
    if (!data?.user) return;
    const user = data.user;
    const firstName = user.name?.split(' ')[0] || user.company_name || 'Utilisateur';
    const initial = firstName.charAt(0).toUpperCase();

    const avatar = document.getElementById('topbarAvatar');
    const username = document.getElementById('topbarUsername');
    if (avatar) avatar.textContent = initial;
    if (username) username.textContent = firstName;
  }

  if (protectedPages.includes(currentPage)) {
    fetch('backend/session.php', { credentials: 'same-origin' })
      .then(response => response.json())
      .then(data => {
        if (!data.logged_in) {
          window.location.href = 'connexion.html';
        } else {
          updateUserUI(data);
          updateNotificationBadge();
        }
      })
      .catch(() => {
        window.location.href = 'connexion.html';
      });
  }
});
