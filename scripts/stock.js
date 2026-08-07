/* FacTo - Module Stock : entrees, seuils, alertes */

const stockState = {
  items: [],
  pagination: { page: 1, per_page: 15, total: 0, pages: 0 },
  filters: { q: '', category: '', alert: '', page: 1 },
  editingId: null,
  products: [],
  stats: null,
};

async function api(path, options = {}) {
  const headers = {};
  if (options.body) { headers["Content-Type"] = "application/json"; }
  if (options.headers) Object.assign(headers, options.headers);

  if (options.body && ['POST','PUT','DELETE','PATCH'].includes(options.method)) {
    try {
      const _b = JSON.parse(options.body);
      _b._csrf = await fetchCsrfToken();
      if (!_b._csrf) throw new Error('Token de sécurité indisponible');
      options.body = JSON.stringify(_b);
    } catch (e) {
      throw new Error(e.message || 'Token de sécurité manquant');
    }
  }
  const response = await fetch('backend/stock.php?action=' + path, {
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

  // Token CSRF expiré ou invalidé : on le rafraîchit et on réessaie une seule fois
  if (response.status === 403 && String(data.error || '').includes('CSRF') && !options._csrfRetried) {
    options._csrfRetried = true;
    try {
      const _b = JSON.parse(options.body);
      _b._csrf = await fetchCsrfToken(true);
      options.body = JSON.stringify(_b);
      return api(path, options);
    } catch (e) { /* on laisse tomber : l'erreur d'origine est renvoyée ci-dessous */ }
  }

  if (!response.ok) {
    throw new Error(data.error || 'Erreur serveur (' + response.status + ')');
  }
  return data;
}

function esc(value) {
  return String(value == null ? '' : value)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function formatCurrency(amount) {
  return window.formatMoney ? window.formatMoney(amount) : new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(Number(amount) || 0);
}

async function refreshStock() {
  try {
    const params = new URLSearchParams({
      q: stockState.filters.q,
      category: stockState.filters.category,
      alert: stockState.filters.alert,
      page: String(stockState.filters.page),
      per_page: String(stockState.pagination.per_page || 15),
    });
    const data = await api('list&' + params.toString());
    stockState.items = data.stock || [];
    stockState.pagination = data.pagination || stockState.pagination;
    stockState.stats = data.stats || null;
  } catch (error) {
    toast(error.message, 'error');
    return;
  }
  renderTable();
  renderPagination();
  renderStats();
  renderSearchClear();
}

async function loadProducts() {
  try {
    const data = await api('products');
    stockState.products = data.products || [];
    const select = document.getElementById('stockProduct');
    if (!select) return;
    select.innerHTML = '<option value="">Selectionner un produit...</option>' +
      stockState.products.map(p =>
        `<option value="${p.id}">${esc(p.name)} (${esc(p.reference)}) — ${formatCurrency(p.unit_price)}</option>`
      ).join('');
  } catch (error) {
    toast('Impossible de charger la liste des produits', 'error');
  }
}

function renderStats() {
  const container = document.getElementById('stockStatsCards');
  if (!container) return;
  const items = stockState.items;
  const stats = stockState.stats || {};
  const totalProducts = stats.total_products != null ? Number(stats.total_products) : (stockState.pagination.total || items.length);
  const lowStock = stats.low_stock != null ? Number(stats.low_stock) : items.filter(i => i.quantity <= i.reorder_level && i.reorder_level > 0).length;
  const totalValue = stats.total_value != null ? Number(stats.total_value) : items.reduce((sum, i) => sum + (Number(i.quantity) * Number(i.unit_price || 0)), 0);

  const cards = [
    { label: 'Produits en stock', value: String(totalProducts), change: 'références', variant: 'up', icon: 'bi-box-seam', gradient: 'linear-gradient(135deg, #3b82f6, #60a5fa)' },
    { label: 'Stock faible', value: String(lowStock), change: lowStock > 0 ? 'à réapprovisionner' : 'tout est ok', variant: lowStock > 0 ? 'down' : 'up', icon: 'bi-exclamation-triangle', gradient: 'linear-gradient(135deg, #ef4444, #f87171)' },
    { label: 'Valeur du stock', value: formatCurrency(totalValue), change: 'au prix unitaire', variant: 'up', icon: 'bi-cash-stack', gradient: 'linear-gradient(135deg, #10b981, #34d399)' },
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
  const tbody = document.getElementById('stockTableBody');
  if (!tbody) return;

  if (!stockState.items.length) {
    tbody.innerHTML = `
      <tr><td colspan="8" style="text-align:center;color:var(--gray-500);padding:40px 16px;">
        Aucun article en stock. <a href="#" onclick="openNewStock(event)" style="color:var(--primary);font-weight:600;">Ajouter une entrée</a>.
      </td></tr>`;
    return;
  }

  tbody.innerHTML = stockState.items.map(item => {
    const isLow = Number(item.quantity) <= Number(item.reorder_level) && Number(item.reorder_level) > 0;
    const statusClass = isLow ? 'badge bg-danger' : 'badge bg-success';
    const statusText = isLow ? 'Stock faible' : 'OK';
    return `
    <tr>
      <td>
        <div class="supplier-table-cell">
          <span class="supplier-name">${esc(item.product_name || '')}</span>
        </div>
      </td>
      <td style="color:var(--gray-700);font-size:.85rem;">${esc(item.reference || '-')}</td>
      <td><span class="badge bg-secondary">${esc(item.category || '-')}</span></td>
      <td>
        <span class="${isLow ? 'text-danger fw-bold' : ''}">${Number(item.quantity)}</span>
      </td>
      <td style="color:var(--gray-700);">${Number(item.reorder_level)}</td>
      <td style="color:var(--gray-700);">${formatCurrency(Number(item.quantity) * Number(item.unit_price || 0))}</td>
      <td><span class="${statusClass}">${statusText}</span></td>
      <td>
        <div class="invoice-table-actions">
          <button type="button" title="Modifier" data-edit="${item.id}"><i class="bi bi-pencil"></i></button>
          <button type="button" title="Supprimer" class="is-danger" data-del="${item.id}"><i class="bi bi-trash"></i></button>
        </div>
      </td>
    </tr>`;
  }).join('');
}

function renderSearchClear() {
  const info = document.getElementById('stockTableInfo');
  if (!info) return;
  const total = stockState.pagination.total;
  info.textContent = total === 0 ? 'Aucun article' : (total + ' article' + (total > 1 ? 's' : '') + ' trouve' + (total > 1 ? 's' : ''));
}

function renderPagination() {
  const nav = document.getElementById('stockPagination');
  const p = stockState.pagination;
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

function openNewStock(event) {
  if (event) event.preventDefault();
  stockState.editingId = null;
  document.getElementById('stockModalTitle').textContent = 'Entrée de stock';
  document.getElementById('stockId').value = '';
  document.getElementById('stockForm').reset();
  loadProducts();
  openModal('stockModal');
}

function openEditStock(stockId) {
  api('get&id=' + stockId)
    .then(data => {
      const item = data.stock;
      stockState.editingId = item.id;
      document.getElementById('stockModalTitle').textContent = 'Modifier stock — ' + (item.product_name || '');
      document.getElementById('stockId').value = item.id;
      document.getElementById('stockProduct').value = item.product_id;
      document.getElementById('stockQuantity').value = item.quantity;
      document.getElementById('stockReorderLevel').value = item.reorder_level;
      openModal('stockModal');
    })
    .catch(error => toast(error.message, 'error'));
}

async function submitStock(event) {
  event.preventDefault();
  const saveBtn = document.getElementById('btnSaveStock');
  saveBtn.disabled = true;

  const productSelect = document.getElementById('stockProduct');
  const productId = productSelect.value ? parseInt(productSelect.value, 10) : 0;

  const payload = {
    id: stockState.editingId,
    product_id: productId,
    quantity: parseInt(document.getElementById('stockQuantity').value, 10) || 0,
    reorder_level: parseInt(document.getElementById('stockReorderLevel').value, 10) || 0,
  };

  try {
    const data = await api(stockState.editingId ? 'update' : 'create', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
    toast(data.message || 'Stock enregistré', 'success');
    closeModal('stockModal');
    await refreshStock();
  } catch (error) {
    toast(error.message, 'error');
  } finally {
    saveBtn.disabled = false;
  }
}

async function deleteStock(stockId) {
  const item = stockState.items.find(i => String(i.id) === String(stockId));
  const ok = await uiConfirm({
    title: 'Supprimer l\'entrée de stock ?',
    text: 'Cette action est irreversible.',
    confirmText: 'Supprimer',
    icon: 'bi-trash',
    danger: true,
  });
  if (!ok) return;
  try {
    const data = await api('delete', { method: 'POST', body: JSON.stringify({ id: stockId }) });
    toast(data.message || 'Entrée supprimée', 'success');
    await refreshStock();
  } catch (error) {
    toast(error.message, 'error');
  }
}

function attachEvents() {
  document.getElementById('btnNewStock').addEventListener('click', openNewStock);
  document.getElementById('stockForm').addEventListener('submit', submitStock);

  document.getElementById('stockTableBody').addEventListener('click', event => {
    const editBtn = event.target.closest('[data-edit]');
    const delBtn = event.target.closest('[data-del]');
    if (editBtn) openEditStock(editBtn.getAttribute('data-edit'));
    else if (delBtn) deleteStock(delBtn.getAttribute('data-del'));
  });

  document.getElementById('stockPagination').addEventListener('click', event => {
    const btn = event.target.closest('[data-page]');
    if (!btn || btn.classList.contains('disabled')) return;
    stockState.filters.page = Number(btn.getAttribute('data-page'));
    refreshStock().catch(error => toast(error.message, 'error'));
  });

  const searchInput = document.getElementById('stockSearch');
  if (searchInput) {
    let timer;
    searchInput.addEventListener('input', () => {
      clearTimeout(timer);
      timer = setTimeout(() => {
        stockState.filters.q = searchInput.value.trim();
        stockState.filters.page = 1;
        refreshStock().catch(error => toast(error.message, 'error'));
      }, 350);
    });
  }

  const catFilter = document.getElementById('stockCategoryFilter');
  if (catFilter) {
    catFilter.addEventListener('change', () => {
      stockState.filters.category = catFilter.value;
      stockState.filters.page = 1;
      refreshStock().catch(error => toast(error.message, 'error'));
    });
  }

  const alertFilter = document.getElementById('stockAlertFilter');
  if (alertFilter) {
    alertFilter.addEventListener('change', () => {
      stockState.filters.alert = alertFilter.value;
      stockState.filters.page = 1;
      refreshStock().catch(error => toast(error.message, 'error'));
    });
  }

  document.getElementById('btnRefreshStock').addEventListener('click', () => {
    refreshStock().catch(error => toast(error.message, 'error'));
  });
}

document.addEventListener('DOMContentLoaded', () => {
  attachEvents();
  loadProducts().catch(() => {});
  refreshStock().catch(error => toast(error.message, 'error'));
});