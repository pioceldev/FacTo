const state = {
  user: null,
  stats: null,
  invoices: [],
  notifications: [],
  subscription: null,
};

function formatCurrency(amount) {
  return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(amount || 0);
}

function getStatusClass(status) {
  const normalized = (status || '').toLowerCase();
  if (normalized.includes('pay')) return 'badge-soft-success';
  if (normalized.includes('attente') || normalized.includes('pending')) return 'badge-soft-warning';
  if (normalized.includes('retard') || normalized.includes('overdue')) return 'badge-soft-danger';
  if (normalized.includes('brouillon') || normalized.includes('draft')) return 'badge-soft-secondary';
  return 'badge-soft-secondary';
}

function formatRelativeDate(dateString) {
  try {
    const date = new Date(dateString);
    const diffMs = Date.now() - date.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    if (diffMins < 1) return 'À l\'instant';
    if (diffMins < 60) return `Il y a ${diffMins} min`;
    const diffHours = Math.floor(diffMins / 60);
    if (diffHours < 24) return `Il y a ${diffHours}h`;
    const diffDays = Math.floor(diffHours / 24);
    if (diffDays < 7) return `Il y a ${diffDays}j`;
    return date.toLocaleDateString('fr-FR');
  } catch {
    return dateString;
  }
}

function getGreeting() {
  const hour = new Date().getHours();
  if (hour < 12) return 'Bonjour';
  if (hour < 18) return 'Bon après-midi';
  return 'Bonsoir';
}

function renderWelcome() {
  const welcomeName = document.getElementById('welcomeName');
  const topbarUsername = document.getElementById('topbarUsername');
  const topbarAvatar = document.getElementById('topbarAvatar');
  const welcomeTitle = document.querySelector('.dashboard__welcome-title');

  const firstName = state.user?.name?.split(' ')[0] || state.user?.company_name || 'Utilisateur';

  if (welcomeName) welcomeName.textContent = firstName;
  if (topbarUsername) topbarUsername.textContent = firstName;
  if (topbarAvatar) {
    const initial = firstName.charAt(0).toUpperCase();
    topbarAvatar.innerHTML = initial;
  }
  if (welcomeTitle) {
    welcomeTitle.innerHTML = `${getGreeting()}, <span id="welcomeName">${firstName}</span> &nbsp;👋`;
  }

  const welcomeDate = document.getElementById('welcomeDate');
  if (welcomeDate) {
    const today = new Date().toLocaleDateString('fr-FR', {
      weekday: 'long',
      day: 'numeric',
      month: 'long',
    });
    welcomeDate.textContent = `Nous sommes le ${today}`;
  }
}

function renderStats() {
  const statsContainer = document.getElementById('dashboardStatsCards');
  if (!statsContainer) return;

  const stats = state.stats || {
    monthly_revenue: 0,
    invoice_count: 0,
    new_clients: 0,
    ticket_response_rate: 0,
  };

  const cards = [
    {
      label: "Chiffre d'affaires",
      value: formatCurrency(stats.monthly_revenue),
      change: "+12% ce mois",
      variant: 'up',
      icon: 'bi-currency-euro',
      gradient: 'linear-gradient(135deg, #f97316, #fb923c)',
    },
    {
      label: "Factures émises",
      value: stats.invoice_count.toString(),
      change: "+8% cette semaine",
      variant: 'up',
      icon: 'bi-receipt',
      gradient: 'linear-gradient(135deg, #3b82f6, #60a5fa)',
    },
    {
      label: "Nouveaux clients",
      value: stats.new_clients.toString(),
      change: "+30 derniers jours",
      variant: 'up',
      icon: 'bi-people',
      gradient: 'linear-gradient(135deg, #10b981, #34d399)',
    },
    {
      label: "Taux de réponse",
      value: `${stats.ticket_response_rate}%`,
      change: "Support client",
      variant: 'up',
      icon: 'bi-chat-dots',
      gradient: 'linear-gradient(135deg, #8b5cf6, #a78bfa)',
    },
  ];

  statsContainer.innerHTML = cards.map((stat, i) => `
    <div class="stat-card animate-in" style="animation-delay: ${i * 0.08}s">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <div class="stat-card__label">${stat.label}</div>
          <div class="stat-card__value">${stat.value}</div>
          <div class="stat-card__change ${stat.variant}">
            <i class="bi bi-arrow-${stat.variant === 'up' ? 'up-right' : 'down-right'}"></i>
            ${stat.change}
          </div>
        </div>
        <div class="stat-icon" style="background:${stat.gradient};color:#fff;">
          <i class="bi ${stat.icon}"></i>
        </div>
      </div>
    </div>
  `).join('');
}

function renderInvoices(filter = 'all', query = '') {
  const invoicesContainer = document.getElementById('dashboardInvoicesRows');
  if (!invoicesContainer) return;

  const search = query.toLowerCase().trim();
  const filteredInvoices = state.invoices.filter(invoice => {
    const status = (invoice.status || '').toLowerCase();
    const statusMatch = filter === 'all'
      || (filter === 'pending' && (status.includes('attente') || status.includes('pending')))
      || (filter === 'overdue' && (status.includes('retard') || status.includes('overdue')));
    const invoiceRef = (invoice.ref || '').toLowerCase();
    const invoiceClient = (invoice.client_name || '').toLowerCase();
    const invoiceDate = (invoice.due_date || '').toLowerCase();
    const invoiceTotal = ((invoice.total || invoice.amount || 0).toString()).toLowerCase();
    const queryMatch = !search
      || invoiceRef.includes(search)
      || invoiceClient.includes(search)
      || invoiceDate.includes(search)
      || invoiceTotal.includes(search);
    return statusMatch && queryMatch;
  });

  if (!filteredInvoices.length) {
    invoicesContainer.innerHTML = `
      <div class="text-center py-4" style="color: var(--gray-500);">
        <i class="bi bi-inbox" style="font-size:2rem; opacity:.4;"></i>
        <p class="mt-2 mb-0">Aucun résultat trouv&eacute;.</p>
      </div>
    `;
    return;
  }

  invoicesContainer.innerHTML = filteredInvoices.slice(0, 5).map(invoice => {
    const amount = formatCurrency(invoice.total ?? invoice.amount ?? 0);
    const dateValue = invoice.due_date || invoice.issued_at || '';
    const dateText = dateValue ? new Date(dateValue).toLocaleDateString('fr-FR') : '-';
    const clientName = invoice.client_name || 'Client inconnu';
    const statusClass = getStatusClass(invoice.status);
    return `
      <div class="dashboard__invoice-row">
        <div class="dashboard__invoice-ref">${invoice.ref || '-'}</div>
        <div class="dashboard__invoice-client">${clientName}</div>
        <div class="dashboard__invoice-date">${dateText}</div>
        <div class="dashboard__invoice-amount">${amount}</div>
        <div><span class="badge-soft ${statusClass}">${invoice.status || '-'}</span></div>
        <div class="dashboard__invoice-actions">
          <button type="button" aria-label="T&eacute;l&eacute;charger" title="T&eacute;l&eacute;charger">
            <i class="bi bi-download"></i>
          </button>
        </div>
      </div>
    `;
  }).join('');
}

function renderActivity() {
  const activityContainer = document.getElementById('dashboardActivityRows');
  if (!activityContainer) return;

  const activity = state.notifications.slice(0, 5).map(notification => ({
    title: notification.title || 'Notification',
    time: formatRelativeDate(notification.created_at),
    isRead: notification.is_read,
    icon: notification.is_read ? 'bi-check2-circle' : 'bi-bell-fill',
    color: notification.is_read ? 'var(--success)' : 'var(--primary)',
    bg: notification.isRead ? 'rgba(16,185,129,0.1)' : 'rgba(249,115,22,0.1)',
  }));

  if (!activity.length) {
    activityContainer.innerHTML = `
      <div class="text-center py-4" style="color: var(--gray-500);">
        <i class="bi bi-bell-slash" style="font-size:1.5rem; opacity:.4;"></i>
        <p class="mt-2 mb-0">Aucune activit&eacute; r&eacute;cente.</p>
      </div>
    `;
    return;
  }

  activityContainer.innerHTML = activity.map(item => `
    <div class="activity-item">
      <div class="activity-item__icon" style="background:${item.bg};color:${item.color};">
        <i class="bi ${item.icon}"></i>
      </div>
      <div class="activity-item__content">
        <div class="activity-item__title">${item.title}</div>
        <div class="activity-item__time">
          <i class="bi bi-clock"></i> ${item.time}
        </div>
      </div>
    </div>
  `).join('');

  const badge = document.getElementById('notificationBadge');
  if (badge) {
    const unreadCount = state.notifications.filter(n => !n.is_read).length;
    if (unreadCount > 0) {
      badge.style.display = 'block';
      badge.textContent = unreadCount > 9 ? '9+' : unreadCount;
    }
  }
}

function renderSubscription() {
  const subscriptionContainer = document.getElementById('subscriptionCard');
  if (!subscriptionContainer) return;

  if (!state.subscription || !state.subscription.plan_name) {
    subscriptionContainer.innerHTML = `
      <div class="dashboard-subscription-empty">
        <div>
          <div class="dashboard__card-title">Aucun abonnement actif</div>
          <p class="mt-2">D&eacute;bloquez toutes les fonctionnalit&eacute;s en choisissant un plan adapt&eacute; &agrave; votre activit&eacute;.</p>
        </div>
        <a href="choix-abonnement.html" class="btn btn-primary">
          <i class="bi bi-crown"></i> Choisir un plan
        </a>
      </div>
    `;
    return;
  }

  const statusClass = state.subscription.status?.toLowerCase().includes('active')
    ? 'badge-soft-success'
    : 'badge-soft-warning';

  subscriptionContainer.innerHTML = `
    <div class="dashboard-subscription-grid">
      <div>
        <div class="dashboard-subscription__label">Plan actuel</div>
        <div class="dashboard-subscription__value">${state.subscription.plan_name}</div>
      </div>
      <div>
        <div class="dashboard-subscription__label">Statut</div>
        <div><span class="badge-soft ${statusClass}">${state.subscription.status || 'Actif'}</span></div>
      </div>
      <div>
        <div class="dashboard-subscription__label">Prochaine facturation</div>
        <div class="dashboard-subscription__value">${state.subscription.next_billing_date || '-'}</div>
      </div>
      <div>
        <div class="dashboard-subscription__label">Renouvellement</div>
        <div class="dashboard-subscription__value">${state.subscription.renews_at || '-'}</div>
      </div>
    </div>
    <div class="mt-3">
      <a href="choix-abonnement.html" class="btn btn-primary">
        <i class="bi bi-arrow-up-circle"></i> G&eacute;rer mon abonnement
      </a>
    </div>
  `;
}

function renderCharts() {
  const evolutionCanvas = document.getElementById('evolutionChart');
  const donutCanvas = document.getElementById('donutChart');

  if (evolutionCanvas && typeof Chart !== 'undefined') {
    const labels = state.stats?.chart_labels || ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Jun'];
    const revenueData = state.stats?.revenue_data || [0, 0, 0, 0, 0, 0];

    new Chart(evolutionCanvas, {
      type: 'line',
      data: {
        labels,
        datasets: [{
          label: 'Revenus',
          data: revenueData,
          borderColor: '#f97316',
          backgroundColor: 'rgba(249, 115, 22, 0.08)',
          fill: true,
          tension: 0.4,
          borderWidth: 2.5,
          pointRadius: 4,
          pointBackgroundColor: '#f97316',
          pointBorderColor: '#fff',
          pointBorderWidth: 2,
          pointHoverRadius: 6,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: '#111827',
            titleFont: { size: 13 },
            bodyFont: { size: 13 },
            padding: 12,
            cornerRadius: 10,
            callbacks: {
              label: (ctx) => formatCurrency(ctx.raw),
            },
          },
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: { font: { size: 11 }, color: '#9ca3af' },
          },
          y: {
            grid: { color: 'rgba(0,0,0,0.04)' },
            ticks: {
              font: { size: 11 },
              color: '#9ca3af',
              callback: (val) => val >= 1000 ? `${(val/1000).toFixed(0)}k€` : `${val}€`,
            },
          },
        },
      },
    });
  }

  if (donutCanvas && typeof Chart !== 'undefined') {
    const donutData = state.stats?.donut_data || [45, 30, 15, 10];

    document.getElementById('legendPaid').textContent = donutData[0] + '%';
    document.getElementById('legendPending').textContent = donutData[1] + '%';
    document.getElementById('legendOverdue').textContent = donutData[2] + '%';
    document.getElementById('legendDraft').textContent = donutData[3] + '%';

    new Chart(donutCanvas, {
      type: 'doughnut',
      data: {
        labels: ['Payées', 'En attente', 'En retard', 'Brouillons'],
        datasets: [{
          data: donutData,
          backgroundColor: ['#10b981', '#f59e0b', '#ef4444', '#9ca3af'],
          borderWidth: 0,
          hoverOffset: 6,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '70%',
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: '#111827',
            padding: 10,
            cornerRadius: 8,
            callbacks: {
              label: (ctx) => `${ctx.label}: ${ctx.raw}%`,
            },
          },
        },
      },
    });
  }
}

async function fetchDashboardData() {
  const response = await fetch('backend/dashboard.php', { credentials: 'same-origin' });
  if (response.status === 401) {
    window.location.href = 'connexion.html';
    return Promise.reject(new Error('Non autorisé'));
  }
  if (!response.ok) throw new Error('Impossible de charger les données du tableau de bord');
  return response.json();
}

async function fetchInvoices() {
  const response = await fetch('backend/invoices.php', { credentials: 'same-origin' });
  if (response.status === 401) {
    window.location.href = 'connexion.html';
    return Promise.reject(new Error('Non autorisé'));
  }
  if (!response.ok) throw new Error('Impossible de charger les factures');
  return response.json();
}

async function fetchNotifications() {
  const response = await fetch('backend/notifications.php', { credentials: 'same-origin' });
  if (response.status === 401) {
    window.location.href = 'connexion.html';
    return Promise.reject(new Error('Non autorisé'));
  }
  if (!response.ok) throw new Error('Impossible de charger les notifications');
  return response.json();
}

async function fetchSubscription() {
  const response = await fetch('backend/subscription.php', { credentials: 'same-origin' });
  if (response.status === 401) {
    window.location.href = 'connexion.html';
    return Promise.reject(new Error('Non autorisé'));
  }
  if (!response.ok) throw new Error('Impossible de charger l\'abonnement');
  return response.json();
}

async function loadDashboard() {
  try {
    const [dashboardData, invoicesData, notificationsData, subscriptionData] = await Promise.all([
      fetchDashboardData(),
      fetchInvoices(),
      fetchNotifications(),
      fetchSubscription(),
    ]);

    state.user = dashboardData.user || null;
    state.stats = dashboardData.stats || null;
    state.invoices = invoicesData.invoices || [];
    state.notifications = notificationsData.notifications || [];
    state.subscription = subscriptionData.subscription || null;
  } catch (error) {
    console.error(error);
  }

  renderWelcome();
  renderStats();
  renderSubscription();
  renderInvoices();
  renderActivity();
  renderCharts();
}

function updateFilterButton(filterButton) {
  if (!filterButton) return;
  const currentFilter = filterButton.getAttribute('data-filter');
  const label = currentFilter === 'all' ? 'Tous' : currentFilter === 'pending' ? 'En attente' : 'En retard';
  filterButton.innerHTML = `<i class="bi bi-funnel"></i> ${label}`;
}

function updateRangeButton(rangeButton) {
  if (!rangeButton) return;
  const currentRange = rangeButton.getAttribute('data-range');
  rangeButton.innerHTML = `<i class="bi bi-calendar"></i> ${currentRange} jours`;
}

document.addEventListener('DOMContentLoaded', () => {
  loadDashboard();

  const rangeButton = document.getElementById('dashboardRangeButton');
  const filterButton = document.getElementById('dashboardFilterButton');
  const searchInput = document.getElementById('dashboardSearchInput');

  updateFilterButton(filterButton);
  updateRangeButton(rangeButton);

  if (rangeButton) {
    rangeButton.addEventListener('click', () => {
      const currentRange = rangeButton.getAttribute('data-range');
      const newRange = currentRange === '30' ? '90' : '30';
      rangeButton.setAttribute('data-range', newRange);
      updateRangeButton(rangeButton);
    });
  }

  if (filterButton) {
    filterButton.addEventListener('click', () => {
      const currentFilter = filterButton.getAttribute('data-filter');
      const nextFilter = currentFilter === 'all' ? 'pending' : currentFilter === 'pending' ? 'overdue' : 'all';
      filterButton.setAttribute('data-filter', nextFilter);
      updateFilterButton(filterButton);
      renderInvoices(nextFilter, searchInput?.value || '');
    });
  }

  if (searchInput) {
    searchInput.addEventListener('input', () => {
      const currentFilter = filterButton?.getAttribute('data-filter') || 'all';
      renderInvoices(currentFilter, searchInput.value);
    });
  }


});
