/* FacTo - Module Rapports : tableaux de bord analytiques */

const reportState = {
  overview: null,
  charts: {},
};

async function api(path, options = {}) {
  const headers = {};
  if (options.body) headers['Content-Type'] = 'application/json';
  if (options.headers) Object.assign(headers, options.headers);

  const response = await fetch('backend/reports.php?action=' + path, {
    credentials: 'same-origin',
    headers,
    ...options,
  });

  if (response.status === 401) {
    window.location.href = 'connexion.html';
    throw new Error('Non autorise');
  }

  let data = {};
  try {
    data = await response.json();
  } catch (e) { /* ignore */ }

  if (!response.ok) {
    throw new Error(data.error || 'Erreur serveur (' + response.status + ')');
  }
  return data;
}

function formatCurrency(amount) {
  return window.formatMoney ? window.formatMoney(amount) : new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(Number(amount) || 0);
}

/* ============ EXPORT CSV ============ */
function csvCell(value) {
  const s = String(value == null ? '' : value);
  // Échappe les cellules contenant séparateur, guillemets ou sauts de ligne
  return /[;"\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
}

function csvSection(lines) {
  return lines.map(row => row.map(csvCell).join(';')).join('\r\n');
}

// Format numérique Excel FR : virgule décimale, 2 décimales
function fmtNum(value) {
  return (Number(value) || 0).toFixed(2).replace('.', ',');
}

async function exportCSV() {
  try {
    const [overviewRes, revenueRes, statusRes, expensesRes, categoriesRes, methodsRes] = await Promise.all([
      api('overview'),
      api('monthly_revenue'),
      api('invoice_status'),
      api('monthly_expenses'),
      api('expense_by_category'),
      api('payment_methods'),
    ]);

    const o = overviewRes.overview || {};
    const profit = (Number(o.total_revenue) || 0) - (Number(o.total_expenses) || 0);
    const now = new Date();
    const dateLabel = now.toLocaleDateString('fr-FR');

    const sections = [];

    sections.push('RÉCAPITULATIF FACTO — généré le ' + dateLabel);
    sections.push('');
    sections.push(csvSection([
      ['Indicateur', 'Valeur (€)'],
      ['Chiffre d\'affaires', fmtNum(o.total_revenue)],
      ['Dépenses', fmtNum(o.total_expenses)],
      ['Profit net', fmtNum(profit)],
      ['Factures payées', (o.paid_invoices || 0) + ' / ' + (o.total_invoices || 0)],
      ['Clients', o.total_clients || 0],
    ]));

    sections.push('');
    sections.push('CHIFFRE D\'AFFAIRES MENSUEL');
    sections.push(csvSection([
      ['Mois', 'Revenus (€)'],
      ...(revenueRes.monthly_revenue || []).map(d => [d.month, fmtNum(d.revenue)]),
    ]));

    sections.push('');
    sections.push('DÉPENSES MENSUELLES');
    sections.push(csvSection([
      ['Mois', 'Dépenses (€)'],
      ...(expensesRes.monthly_expenses || []).map(d => [d.month, fmtNum(d.expenses)]),
    ]));

    sections.push('');
    sections.push('DÉPENSES PAR CATÉGORIE');
    sections.push(csvSection([
      ['Catégorie', 'Montant (€)'],
      ...(categoriesRes.expense_by_category || []).map(d => [d.category || 'Autre', fmtNum(d.total)]),
    ]));

    sections.push('');
    sections.push('STATUT DES FACTURES');
    sections.push(csvSection([
      ['Statut', 'Nombre'],
      ...(statusRes.invoice_status || []).map(d => [d.status, d.count]),
    ]));

    sections.push('');
    sections.push('MÉTHODES DE PAIEMENT');
    sections.push(csvSection([
      ['Méthode', 'Montant (€)'],
      ...(methodsRes.payment_methods || []).map(d => [d.payment_method || '—', fmtNum(d.total)]),
    ]));

    // BOM UTF-8 pour les accents (Excel) + séparateur point-virgule
    const blob = new Blob(['\uFEFF' + sections.join('\r\n\r\n')], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'rapports-facto-' + now.toISOString().slice(0, 10) + '.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);

    toast('Export CSV généré avec succès', 'success');
  } catch (error) {
    toast(error.message, 'error');
  }
}

function destroyChart(key) {
  if (reportState.charts[key]) {
    reportState.charts[key].destroy();
    reportState.charts[key] = null;
  }
}

function createChart(canvasId, config) {
  const canvas = document.getElementById(canvasId);
  if (!canvas) return null;
  destroyChart(canvasId);
  const ctx = canvas.getContext('2d');
  reportState.charts[canvasId] = new Chart(ctx, config);
  return reportState.charts[canvasId];
}

async function loadReports() {
  try {
    const overview = await api('overview');
    reportState.overview = overview.overview;
    renderStats();

    const monthlyRevenue = await api('monthly_revenue');
    renderMonthlyRevenueChart(monthlyRevenue.monthly_revenue);

    const invoiceStatus = await api('invoice_status');
    renderInvoiceStatusChart(invoiceStatus.invoice_status);

    const monthlyExpenses = await api('monthly_expenses');
    renderMonthlyExpensesChart(monthlyExpenses.monthly_expenses);

    const expenseCategory = await api('expense_by_category');
    renderExpenseCategoryChart(expenseCategory.expense_by_category);

    const paymentMethods = await api('payment_methods');
    renderPaymentMethodsChart(paymentMethods.payment_methods);
  } catch (error) {
    toast(error.message, 'error');
  }
}

function renderStats() {
  const container = document.getElementById('reportsStatsCards');
  if (!container) return;
  const o = reportState.overview;
  if (!o) return;
  const profit = (o.total_revenue || 0) - (o.total_expenses || 0);

  const cards = [
    { label: "Chiffre d'affaires", value: formatCurrency(o.total_revenue), change: 'revenus totaux', variant: 'up', icon: 'bi-graph-up-arrow', gradient: 'linear-gradient(135deg, #3b82f6, #60a5fa)' },
    { label: 'Dépenses', value: formatCurrency(o.total_expenses), change: 'charges enregistrées', variant: 'down', icon: 'bi-wallet2', gradient: 'linear-gradient(135deg, #ef4444, #f87171)' },
    { label: 'Profit net', value: formatCurrency(profit), change: profit >= 0 ? 'résultat positif' : 'résultat négatif', variant: profit >= 0 ? 'up' : 'down', icon: 'bi-piggy-bank', gradient: 'linear-gradient(135deg, #10b981, #34d399)' },
    { label: 'Factures payées', value: `${o.paid_invoices || 0} / ${o.total_invoices || 0}`, change: 'sur le total émis', variant: 'up', icon: 'bi-check2-square', gradient: 'linear-gradient(135deg, #8b5cf6, #a78bfa)' },
  ];

  container.innerHTML = cards.map((stat, i) => `
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

function renderMonthlyRevenueChart(data) {
  createChart('chartMonthlyRevenue', {
    type: 'line',
    data: {
      labels: data.map(d => d.month),
      datasets: [{
        label: 'Chiffre d\'affaires',
        data: data.map(d => d.revenue),
        borderColor: '#2563eb',
        backgroundColor: 'rgba(37, 99, 235, 0.1)',
        fill: true,
        tension: 0.4,
      }],
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false } },
      scales: {
        y: { beginAtZero: true, ticks: { callback: v => formatCurrency(v) } },
      },
    },
  });
}

function renderMonthlyExpensesChart(data) {
  createChart('chartMonthlyExpenses', {
    type: 'bar',
    data: {
      labels: data.map(d => d.month),
      datasets: [{
        label: 'Dépenses',
        data: data.map(d => d.expenses),
        backgroundColor: '#ef4444',
        borderRadius: 4,
      }],
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false } },
      scales: {
        y: { beginAtZero: true, ticks: { callback: v => formatCurrency(v) } },
      },
    },
  });
}

function renderInvoiceStatusChart(data) {
  const colors = {
    'brouillon': '#6b7280',
    'envoyé': '#3b82f6',
    'payée': '#10b981',
    'refusé': '#ef4444',
    'annulée': '#f59e0b',
  };
  createChart('chartInvoiceStatus', {
    type: 'doughnut',
    data: {
      labels: data.map(d => d.status),
      datasets: [{
        data: data.map(d => d.count),
        backgroundColor: data.map(d => colors[d.status] || '#6b7280'),
      }],
    },
    options: {
      responsive: true,
      plugins: { legend: { position: 'bottom' } },
    },
  });
}

function renderExpenseCategoryChart(data) {
  createChart('chartExpenseCategory', {
    type: 'pie',
    data: {
      labels: data.map(d => d.category || 'Autre'),
      datasets: [{
        data: data.map(d => d.total),
        backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#14b8a6'],
      }],
    },
    options: {
      responsive: true,
      plugins: { legend: { position: 'bottom' } },
    },
  });
}

function renderPaymentMethodsChart(data) {
  createChart('chartPaymentMethods', {
    type: 'bar',
    data: {
      labels: data.map(d => d.payment_method),
      datasets: [{
        label: 'Montant total',
        data: data.map(d => d.total),
        backgroundColor: '#8b5cf6',
        borderRadius: 4,
      }],
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false } },
      scales: {
        y: { beginAtZero: true, ticks: { callback: v => formatCurrency(v) } },
      },
    },
  });
}

function attachEvents() {
  document.getElementById('btnRefreshReports').addEventListener('click', () => {
    loadReports().catch(error => toast(error.message, 'error'));
  });

  const exportBtn = document.getElementById('btnExportReports');
  if (exportBtn) {
    exportBtn.addEventListener('click', exportCSV);
  }
}

document.addEventListener('DOMContentLoaded', () => {
  attachEvents();
  loadReports().catch(error => toast(error.message, 'error'));
});