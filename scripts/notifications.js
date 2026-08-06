const notificationState = {
  notifications: [],
  filter: 'all',
  query: '',
};

function formatNotificationTime(createdAt) {
  const now = new Date();
  const date = new Date(createdAt);
  const diffMs = now - date;
  const minutes = Math.floor(diffMs / 60000);
  if (minutes < 1) return 'Il y a quelques secondes';
  if (minutes < 60) return `Il y a ${minutes} minute${minutes > 1 ? 's' : ''}`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `Il y a ${hours} heure${hours > 1 ? 's' : ''}`;
  const days = Math.floor(hours / 24);
  return `Il y a ${days} jour${days > 1 ? 's' : ''}`;
}

function getIconForNotification(notification) {
  if (notification.title.toLowerCase().includes('paiement')) return 'bi-receipt';
  if (notification.title.toLowerCase().includes('stock')) return 'bi-box-seam';
  if (notification.title.toLowerCase().includes('abonnement') || notification.title.toLowerCase().includes('renouvellement')) return 'bi-crown';
  if (notification.title.toLowerCase().includes('mise à jour') || notification.title.toLowerCase().includes('mise a jour')) return 'bi-info-circle';
  return 'bi-bell';
}

function getColorForNotification(notification) {
  if (notification.title.toLowerCase().includes('paiement')) return 'var(--primary)';
  if (notification.title.toLowerCase().includes('stock')) return 'var(--warning)';
  if (notification.title.toLowerCase().includes('abonnement') || notification.title.toLowerCase().includes('renouvellement')) return 'var(--primary)';
  if (notification.title.toLowerCase().includes('mise à jour') || notification.title.toLowerCase().includes('mise a jour')) return 'var(--gray-500)';
  return 'var(--info)';
}

function renderNotifications() {
  const container = document.getElementById('notificationsList');
  const badge = document.getElementById('notificationsCountBadge');
  if (!container || !badge) return;

  const filtered = notificationState.notifications.filter((notification) => {
    const text = `${notification.title} ${notification.body}`.toLowerCase();
    const matchesQuery = notificationState.query.length === 0 || text.includes(notificationState.query.toLowerCase());
    if (!matchesQuery) return false;

    if (notificationState.filter === 'all') return true;
    if (notificationState.filter === 'unread') return !notification.is_read;
    if (notificationState.filter === 'payments') return text.includes('paiement') || text.includes('facture');
    return true;
  });

  if (!filtered.length) {
    container.innerHTML = `
      <div class="notif-item notif-item--empty">
        <div class="notif-item__content">
          <div class="notif-item__title">Aucune notification trouvée.</div>
        </div>
      </div>
    `;
    badge.textContent = '0 Nouvelles';
    return;
  }

  badge.textContent = `${filtered.length} notification${filtered.length > 1 ? 's' : ''}`;

  container.innerHTML = filtered.map((notification) => {
    const icon = getIconForNotification(notification);
    const color = getColorForNotification(notification);
    const unreadClass = notification.is_read ? '' : 'notif-item--unread';
    const statusBadge = notification.is_read ? '' : '<span class="urgent-badge">NOUVEAU</span>';

    return `
      <div class="notif-item ${unreadClass}">
        <div class="notif-item__icon" style="background:${color}1A;color:${color};"><i class="bi ${icon}"></i></div>
        <div class="notif-item__content">
          <div class="notif-item__header">
            <div class="notif-item__title">${notification.title} ${statusBadge}</div>
            <div class="notif-item__time">${formatNotificationTime(notification.created_at)}</div>
          </div>
          <div class="notif-item__desc">${notification.body || ''}</div>
        </div>
      </div>
    `;
  }).join('');
}

async function fetchNotifications() {
  const response = await fetch('backend/notifications.php', { credentials: 'same-origin' });
  if (response.status === 401) {
    window.location.href = 'connexion.html';
    return Promise.reject(new Error('Non autorisé'));
  }
  if (!response.ok) {
    throw new Error('Impossible de charger les notifications');
  }

  const data = await response.json();
  notificationState.notifications = data.notifications || [];
}

function updateTopbarNotificationBadge() {
  const badgeEls = document.querySelectorAll('.topbar__badge');
  const unreadCount = notificationState.notifications.filter(notification => !notification.is_read).length;
  badgeEls.forEach((badgeEl) => {
    badgeEl.textContent = unreadCount ? unreadCount : '';
  });
}

function attachNotificationHandlers() {
  const buttons = document.querySelectorAll('.notif__filter-btn');
  buttons.forEach((button) => {
    button.addEventListener('click', () => {
      buttons.forEach((btn) => btn.classList.remove('is-active'));
      button.classList.add('is-active');
      notificationState.filter = button.getAttribute('data-filter') || 'all';
      renderNotifications();
    });
  });

  const searchInput = document.getElementById('notificationSearchInput');
  if (searchInput) {
    searchInput.addEventListener('input', () => {
      notificationState.query = searchInput.value;
      renderNotifications();
    });
  }

  const markReadBtn = document.getElementById('notificationsMarkReadBtn');
  if (markReadBtn) {
    markReadBtn.addEventListener('click', () => {
      notificationState.notifications = notificationState.notifications.map((notification) => ({
        ...notification,
        is_read: true,
      }));
      renderNotifications();
    });
  }
}

async function initNotificationsPage() {
  try {
    await fetchNotifications();
    attachNotificationHandlers();
    renderNotifications();
    updateTopbarNotificationBadge();
  } catch (error) {
    console.error(error);
  }
}

document.addEventListener('DOMContentLoaded', initNotificationsPage);
