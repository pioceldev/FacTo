/* FacTo - Module Dépenses : suivi des dépenses */

const expenseState = {
  expenses: [],
  pagination: { page: 1, per_page: 15, total: 0, pages: 0 },
  filters: { q: '', category: '', period: '', page: 1 },
  editingId: null,
  categories: [],
  stats: null,
};

async function api(path, options = {}) {
  const headers = {};
  if (options.body) { headers["Content-Type"] = "application/json"; }
  if (options.headers) Object.assign(headers, options.headers);

  if (options.body && ['POST','PUT','DELETE','PATCH'].includes(options.method)) { try { const _b = JSON.parse(options.body); _b._csrf = await fetchCsrfToken(); options.body = JSON.stringify(_b); } catch(e) {} }
  const response = await fetch('backend/expenses.php?action=' + path, {
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

let _csrfToken = null; async function fetchCsrfToken() { if (_csrfToken) return _csrfToken; try { const r = await fetch("backend/csrf.php?action=token", { credentials: "same-origin" }); const d = await r.json(); _csrfToken = d.csrf_token; } catch(e) {} return _csrfToken; } function csrfToken() { return _csrfToken ?? ``; } function esc(value) {
  return String(value == null ? '' : value)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function formatCurrency(amount) {
  return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(Number(amount) || 0);
}

async function refreshExpenses() {
  try {
    const params = new URLSearchParams({
      q: expenseState.filters.q,
      category: expenseState.filters.category,
      period: expenseState.filters.period,
      page: String(expenseState.filters.page),
      per_page: String(expenseState.pagination.per_page || 15),
    });
    const data = await api('list&' + params.toString());
    expenseState.expenses = data.expenses || [];
    expenseState.pagination = data.pagination || expenseState.pagination;
    expenseState.stats = data.stats || null;
  } catch (error) {
    toast(error.message, 'error');
    return;
  }
  renderTable();
  renderPagination();
  renderStats();
  renderSearchClear();
}

async function loadCategories() {
  try {
    const data = await api('categories');
    expenseState.categories = data.categories || [];
    const select = document.getElementById('expenseCategoryFilter');
    if (!select) return;
    select.innerHTML = '<option value="">Toutes</option>' +
      expenseState.categories.map(c => `<option value="${esc(c)}">${esc(c)}</option>`).join('');
  } catch (error) {
    toast('Impossible de charger les catégories', 'error');
  }
}

function renderStats() {
  const container = document.getElementById('expenseStatsCards');
  if (!container) return;
  const expenses = expenseState.expenses;
  const stats = expenseState.stats || {};
  const total = stats.total != null ? Number(stats.total) : expenses.reduce((sum, e) => sum + Number(e.amount || 0), 0);
  const now = new Date();
  const monthStart = new Date(now.getFullYear(), now.getMonth(), 1).toISOString().slice(0, 10);
  const monthExpenses = stats.month_total != null ? Number(stats.month_total) : expenses.filter(e => e.expense_date >= monthStart).reduce((sum, e) => sum + Number(e.amount || 0), 0);
  const count = stats.count != null ? Number(stats.count) : (expenseState.pagination.total || expenses.length);

  const cards = [
    { label: 'Total des dépenses', value: formatCurrency(total), change: 'toutes périodes', variant: 'up', icon: 'bi-wallet2', gradient: 'linear-gradient(135deg, #f97316, #fb923c)' },
    { label: 'Dépenses ce mois', value: formatCurrency(monthExpenses), change: 'mois en cours', variant: 'up', icon: 'bi-calendar-month', gradient: 'linear-gradient(135deg, #3b82f6, #60a5fa)' },
    { label: 'Dépenses enregistrées', value: String(count), change: 'au total', variant: 'up', icon: 'bi-receipt-cutoff', gradient: 'linear-gradient(135deg, #8b5cf6, #a78bfa)' },
  ];

  const animate = onceAnimated(container);

  container.innerHTML = cards.map((stat, i) => `
    <div class="stat-card${animate}" style="animation-delay: ${i * 0.08}s">
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

function renderTable() {
  const tbody = document.getElementById('expensesTableBody');
  if (!tbody) return;

  if (!expenseState.expenses.length) {
    tbody.innerHTML = `
      <tr><td colspan="6" style="text-align:center;color:var(--gray-500);padding:40px 16px;">
        Aucune dépense ne correspond a votre recherche. <a href="#" onclick="openNewExpense(event)" style="color:var(--primary);font-weight:600;">Ajouter une dépense</a>.
      </td></tr>`;
    return;
  }

  tbody.innerHTML = expenseState.expenses.map(expense => `
    <tr>
      <td style="color:var(--gray-700);">${esc(expense.expense_date ? expense.expense_date.slice(0, 10) : '-')}</td>
      <td><span class="badge bg-secondary">${esc(expense.category || '-')}</span></td>
      <td style="color:var(--gray-700);">${esc(expense.description || '-')}</td>
      <td style="color:var(--gray-700);">${formatCurrency(Number(expense.amount))}</td>
      <td>${expense.receipt_path ? '<a href="' + esc(expense.receipt_path) + '" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-file-earmark"></i></a>' : '<span style="color:var(--gray-400);">-</span>'}</td>
      <td>
        <div class="invoice-table-actions">
          <button type="button" title="Modifier" data-edit="${expense.id}"><i class="bi bi-pencil"></i></button>
          <button type="button" title="Supprimer" class="is-danger" data-del="${expense.id}"><i class="bi bi-trash"></i></button>
        </div>
      </td>
    </tr>
  `).join('');
}

function renderSearchClear() {
  const info = document.getElementById('expenseTableInfo');
  if (!info) return;
  const total = expenseState.pagination.total;
  info.textContent = total === 0 ? 'Aucune dépense' : (total + ' dépense' + (total > 1 ? 's' : '') + ' trouve' + (total > 1 ? 's' : ''));
}

function renderPagination() {
  const nav = document.getElementById('expensePagination');
  const p = expenseState.pagination;
  if (!nav) return;
  if (p.pages <= 1) { nav.innerHTML = ''; return; }

  const pages = [];
  for (let i = 1; i <= p.pages; i++) {
    if (p.pages > 7 && i > 2 && i < p.pages - 1 && Math.abs(i - p.page) > 1) {
      if (pages[pages.length - 1] !== '...') pages.push('...');
      continue;
    }
    pages.push(i);
  }
  const prevDisabled = p.page <= 1 ? ' disabled' : '';
  const nextDisabled = p.page >= p.pages ? ' disabled' : '';

  nav.innerHTML = `
    <button type="button" class="pagination-custom__link ${prevDisabled}" data-page="${p.page - 1}"><i class="bi bi-chevron-left"></i></button>
    ${pages.map(pg => pg === '...'
      ? '<span class="pagination-custom__link disabled">...</span>'
      : `<button type="button" class="pagination-custom__link ${pg === p.page ? 'active' : ''}" data-page="${pg}">${pg}</button>`
    ).join('')}
    <button type="button" class="pagination-custom__link ${nextDisabled}" data-page="${p.page + 1}"><i class="bi bi-chevron-right"></i></button>
  `;
}

function getModal(id) {
  const el = document.getElementById(id);
  return el ? bootstrap.Modal.getOrCreateInstance(el) : null;
}

function openNewExpense(event) {
  if (event) event.preventDefault();
  expenseState.editingId = null;
  document.getElementById('expenseModalTitle').textContent = 'Nouvelle dépense';
  document.getElementById('expenseId').value = '';
  document.getElementById('expenseForm').reset();
  loadCategories().catch(() => {});
  getModal('expenseModal').show();
}

function openEditExpense(expenseId) {
  api('get&id=' + expenseId)
    .then(data => {
      const expense = data.expense;
      expenseState.editingId = expense.id;
      document.getElementById('expenseModalTitle').textContent = 'Modifier dépense';
      document.getElementById('expenseId').value = expense.id;
      document.getElementById('expenseCategory').value = expense.category || '';
      document.getElementById('expenseDate').value = expense.expense_date ? expense.expense_date.slice(0, 10) : '';
      document.getElementById('expenseDescription').value = expense.description || '';
      document.getElementById('expenseAmount').value = expense.amount;
      document.getElementById('expenseReceipt').value = expense.receipt_path || '';
      loadCategories().catch(() => {});
      getModal('expenseModal').show();
    })
    .catch(error => toast(error.message, 'error'));
}

async function submitExpense(event) {
  event.preventDefault();
  const saveBtn = document.getElementById('btnSaveExpense');
  saveBtn.disabled = true;

  const payload = {
    id: expenseState.editingId,
    category: document.getElementById('expenseCategory').value.trim(),
    description: document.getElementById('expenseDescription').value.trim(),
    amount: parseFloat(document.getElementById('expenseAmount').value) || 0,
    expense_date: document.getElementById('expenseDate').value,
    receipt_path: document.getElementById('expenseReceipt').value.trim(),
  };

  try {
    const data = await api(expenseState.editingId ? 'update' : 'create', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
    toast(data.message || 'Dépense enregistrée', 'success');
    getModal('expenseModal').hide();
    await refreshExpenses();
  } catch (error) {
    toast(error.message, 'error');
  } finally {
    saveBtn.disabled = false;
  }
}

async function deleteExpense(expenseId) {
  const expense = expenseState.expenses.find(e => String(e.id) === String(expenseId));
  const ok = await uiConfirm({
    title: 'Supprimer la dépense ?',
    text: 'La dépense sera supprimée.',
    confirmText: 'Supprimer',
    icon: 'bi-trash',
    danger: true,
  });
  if (!ok) return;
  try {
    const data = await api('delete', { method: 'POST', body: JSON.stringify({ id: expenseId }) });
    toast(data.message || 'Dépense supprimée', 'success');
    await refreshExpenses();
  } catch (error) {
    toast(error.message, 'error');
  }
}

function attachEvents() {
  document.getElementById('btnNewExpense').addEventListener('click', openNewExpense);
  document.getElementById('expenseForm').addEventListener('submit', submitExpense);

  document.getElementById('expensesTableBody').addEventListener('click', event => {
    const editBtn = event.target.closest('[data-edit]');
    const delBtn = event.target.closest('[data-del]');
    if (editBtn) openEditExpense(editBtn.getAttribute('data-edit'));
    else if (delBtn) deleteExpense(delBtn.getAttribute('data-del'));
  });

  document.getElementById('expensePagination').addEventListener('click', event => {
    const btn = event.target.closest('[data-page]');
    if (!btn || btn.classList.contains('disabled')) return;
    expenseState.filters.page = Number(btn.getAttribute('data-page'));
    refreshExpenses().catch(error => toast(error.message, 'error'));
  });

  const searchInput = document.getElementById('expenseFilterSearch');
  if (searchInput) {
    let timer;
    searchInput.addEventListener('input', () => {
      clearTimeout(timer);
      timer = setTimeout(() => {
        expenseState.filters.q = searchInput.value.trim();
        expenseState.filters.page = 1;
        refreshExpenses().catch(error => toast(error.message, 'error'));
      }, 350);
    });
  }

  const catFilter = document.getElementById('expenseCategoryFilter');
  if (catFilter) {
    catFilter.addEventListener('change', () => {
      expenseState.filters.category = catFilter.value;
      expenseState.filters.page = 1;
      refreshExpenses().catch(error => toast(error.message, 'error'));
    });
  }

  const periodFilter = document.getElementById('expensePeriodFilter');
  if (periodFilter) {
    periodFilter.addEventListener('change', () => {
      expenseState.filters.period = periodFilter.value;
      expenseState.filters.page = 1;
      refreshExpenses().catch(error => toast(error.message, 'error'));
    });
  }

  document.getElementById('btnRefreshExpenses').addEventListener('click', () => {
    refreshExpenses().catch(error => toast(error.message, 'error'));
  });
}

document.addEventListener('DOMContentLoaded', () => {
  attachEvents();
  loadCategories().catch(() => {});
  refreshExpenses().catch(error => toast(error.message, 'error'));
});