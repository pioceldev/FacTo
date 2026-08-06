/* FacTo - Module Produits : liste, creation, edition, suppression */

const productState = {
  products: [],
  stats: null,
  pagination: { page: 1, per_page: 15, total: 0, pages: 0 },
  filters: { q: '', active_only: '1', page: 1 },
  editingId: null,
};

function formatCurrency(amount) {
  return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(Number(amount) || 0);
}

async function api(path, options = {}) {
  const headers = {};
  if (options.body) { headers["Content-Type"] = "application/json"; }
  if (options.headers) Object.assign(headers, options.headers);

  if (options.body && ['POST','PUT','DELETE','PATCH'].includes(options.method)) { try { const _b = JSON.parse(options.body); _b._csrf = await fetchCsrfToken(); options.body = JSON.stringify(_b); } catch(e) {} }
  const response = await fetch('backend/products.php?action=' + path, {
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

async function refreshProducts() {
  try {
    const params = new URLSearchParams({
      q: productState.filters.q,
      active_only: productState.filters.active_only,
      page: String(productState.filters.page),
      per_page: String(productState.pagination.per_page || 15),
    });
    const data = await api('list&' + params.toString());
    productState.products = data.products || [];
    productState.stats = data.stats || null;
    productState.pagination = data.pagination || productState.pagination;
  } catch (error) {
    toast(error.message, 'error');
    return;
  }
  renderStats();
  renderTable();
  renderPagination();
  renderSearchClear();
}

function renderStats() {
  const container = document.getElementById('productStatsCards');
  if (!container) return;
  const s = productState.stats || { total: 0, active: 0, total_value: 0 };

  const cards = [
    { label: 'Produits au total', value: String(s.total), icon: 'bi-box-seam', color: 'var(--info)', change: 'produits enregistres' },
    { label: 'Produits actifs', value: String(s.active), icon: 'bi-check-circle', color: 'var(--success)', change: 'en catalogue' },
    { label: 'Valeur catalogue', value: formatCurrency(s.total_value), icon: 'bi-currency-dollar', color: 'var(--primary)', change: 'prix unitaire cumule' },
  ];

  const animate = onceAnimated(container);

  container.innerHTML = cards.map((card, i) => `
    <div class="stat-card${animate}" style="animation-delay: ${i * 0.08}s">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <div class="stat-card__label">${card.label}</div>
          <div class="stat-card__value">${card.value}</div>
          <div class="stat-card__change up"><i class="bi bi-arrow-up-right"></i> ${card.change}</div>
        </div>
        <div class="stat-icon" style="background:rgba(249,115,22,0.1);color:${card.color};"><i class="bi ${card.icon}"></i></div>
      </div>
    </div>
  `).join('');
}

function renderTable() {
  const tbody = document.getElementById('productsTableBody');
  if (!tbody) return;

  if (!productState.products.length) {
    tbody.innerHTML = `
      <tr><td colspan="7" style="text-align:center;color:var(--gray-500);padding:40px 16px;">
        Aucun produit ne correspond a votre recherche. <a href="#" onclick="openNewProduct(event)" style="color:var(--primary);font-weight:600;">Creer un produit</a>.
      </td></tr>`;
    return;
  }

  tbody.innerHTML = productState.products.map(product => {
    const stockClass = (product.stock_quantity || 0) <= 5 ? 'product-stock--low' : 'product-stock--ok';
    const activeBadge = product.is_active ? 'badge-soft-success' : 'badge-soft-secondary';
    const activeLabel = product.is_active ? 'Actif' : 'Inactif';
    return `
      <tr>
        <td>
          <div class="product-table-cell">
            <div>
              <span class="product-name">${esc(product.name || '')}</span>
              ${product.description ? `<span class="product-desc">${esc(product.description)}</span>` : ''}
            </div>
          </div>
        </td>
        <td><span class="product-table-cell__sku">${esc(product.sku || '-')}</span></td>
        <td class="product-price">${formatCurrency(product.unit_price)}</td>
        <td class="product-vat">${product.vat_rate} %</td>
        <td class="product-stock ${stockClass}">${product.stock_quantity ?? 0}</td>
        <td><span class="badge-soft ${activeBadge} product-status">${activeLabel}</span></td>
        <td>
          <div class="invoice-table-actions">
            <button type="button" title="Voir" data-view="${product.id}"><i class="bi bi-eye"></i></button>
            <button type="button" title="Modifier" data-edit="${product.id}"><i class="bi bi-pencil"></i></button>
            <button type="button" title="${product.is_active ? 'Desactiver' : 'Activer'}" data-toggle="${product.id}"><i class="bi bi-${product.is_active ? 'eye-slash' : 'eye'}"></i></button>
            <button type="button" title="Supprimer" class="is-danger" data-del="${product.id}"><i class="bi bi-trash"></i></button>
          </div>
        </td>
      </tr>`;
  }).join('');
}

function renderSearchClear() {
  const info = document.getElementById('productTableInfo');
  if (!info) return;
  const total = productState.pagination.total;
  info.textContent = total === 0 ? 'Aucun produit' : (total + ' produit' + (total > 1 ? 's' : '') + ' trouve' + (total > 1 ? 's' : ''));
}

function renderPagination() {
  const nav = document.getElementById('productPagination');
  const p = productState.pagination;
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

function openNewProduct(event) {
  if (event) event.preventDefault();
  productState.editingId = null;
  document.getElementById('productModalTitle').textContent = 'Nouveau produit';
  document.getElementById('productId').value = '';
  document.getElementById('productForm').reset();
  document.getElementById('productIsActive').value = '1';
  document.getElementById('productVatRate').value = '0';
  document.getElementById('productStock').value = '0';
  getModal('productModal').show();
}

function openEditProduct(productId) {
  api('get&id=' + productId)
    .then(data => {
      const p = data.product;
      productState.editingId = p.id;
      document.getElementById('productModalTitle').textContent = 'Modifier ' + p.name;
      document.getElementById('productId').value = p.id;
      document.getElementById('productName').value = p.name || '';
      document.getElementById('productSku').value = p.sku || '';
      document.getElementById('productDescription').value = p.description || '';
      document.getElementById('productUnitPrice').value = p.unit_price;
      document.getElementById('productVatRate').value = p.vat_rate;
      document.getElementById('productStock').value = p.stock_quantity || 0;
      document.getElementById('productIsActive').value = p.is_active ? '1' : '0';
      getModal('productModal').show();
    })
    .catch(error => toast(error.message, 'error'));
}

async function submitProduct(event) {
  event.preventDefault();
  const saveBtn = document.getElementById('btnSaveProduct');
  saveBtn.disabled = true;

  const payload = {
    id: productState.editingId,
    name: document.getElementById('productName').value.trim(),
    sku: document.getElementById('productSku').value.trim(),
    description: document.getElementById('productDescription').value.trim(),
    unit_price: parseFloat(document.getElementById('productUnitPrice').value) || 0,
    vat_rate: parseFloat(document.getElementById('productVatRate').value) || 0,
    is_active: document.getElementById('productIsActive').value === '1',
  };

  try {
    const data = await api(productState.editingId ? 'update' : 'create', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
    toast(data.message || 'Produit enregistre', 'success');
    getModal('productModal').hide();
    await refreshProducts();
  } catch (error) {
    toast(error.message, 'error');
  } finally {
    saveBtn.disabled = false;
  }
}

async function toggleProductActive(productId) {
  try {
    const data = await api('toggle_active', { method: 'POST', body: JSON.stringify({ id: productId }) });
    toast(data.message, 'success');
    await refreshProducts();
  } catch (error) {
    toast(error.message, 'error');
  }
}

async function deleteProduct(productId) {
  const product = productState.products.find(p => String(p.id) === String(productId));
  const ok = await uiConfirm({
    title: 'Supprimer le produit ?',
    text: 'Le produit ' + (product ? product.name : '') + ' sera supprime. Les lignes de facture le reference seront detachees.',
    confirmText: 'Supprimer',
    icon: 'bi-trash',
    danger: true,
  });
  if (!ok) return;
  try {
    const data = await api('delete', { method: 'POST', body: JSON.stringify({ id: productId }) });
    toast(data.message || 'Produit supprime', 'success');
    await refreshProducts();
  } catch (error) {
    toast(error.message, 'error');
  }
}

function attachEvents() {
  document.getElementById('btnNewProduct').addEventListener('click', openNewProduct);
  document.getElementById('productForm').addEventListener('submit', submitProduct);

  document.getElementById('productsTableBody').addEventListener('click', event => {
    const viewBtn = event.target.closest('[data-view]');
    const editBtn = event.target.closest('[data-edit]');
    const toggleBtn = event.target.closest('[data-toggle]');
    const delBtn = event.target.closest('[data-del]');
    if (viewBtn) viewProduct(viewBtn.getAttribute('data-view'));
    else if (editBtn) openEditProduct(editBtn.getAttribute('data-edit'));
    else if (toggleBtn) toggleProductActive(toggleBtn.getAttribute('data-toggle'));
    else if (delBtn) deleteProduct(delBtn.getAttribute('data-del'));
  });

  document.getElementById('productPagination').addEventListener('click', event => {
    const btn = event.target.closest('[data-page]');
    if (!btn || btn.classList.contains('disabled')) return;
    productState.filters.page = Number(btn.getAttribute('data-page'));
    refreshProducts().catch(error => toast(error.message, 'error'));
  });

  const searchInput = document.getElementById('productFilterSearch');
  const topSearch = document.getElementById('productSearchInput');
  function onSearch() {
    const val = (searchInput ? searchInput.value : '') || (topSearch ? topSearch.value : '');
    productState.filters.q = val.trim();
    productState.filters.page = 1;
    refreshProducts().catch(error => toast(error.message, 'error'));
  }
  if (searchInput) {
    let timer;
    searchInput.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(onSearch, 350); });
  }
  if (topSearch) {
    let timer2;
    topSearch.addEventListener('input', () => { clearTimeout(timer2); timer2 = setTimeout(onSearch, 350); });
  }

  const activeCheckbox = document.getElementById('productFilterActive');
  if (activeCheckbox) {
    activeCheckbox.addEventListener('change', () => {
      productState.filters.active_only = activeCheckbox.checked ? '1' : '0';
      productState.filters.page = 1;
      refreshProducts().catch(error => toast(error.message, 'error'));
    });
  }

  document.getElementById('btnRefreshProducts').addEventListener('click', () => {
    refreshProducts().catch(error => toast(error.message, 'error'));
  });
}

function viewProduct(productId) {
  api('get&id=' + productId)
    .then(data => {
      const p = data.product;
      alert('Produit : ' + p.name + '\nSKU : ' + (p.sku || '-') + '\nP.U. HT : ' + formatCurrency(p.unit_price) + '\nTVA : ' + p.vat_rate + '%\nStock : ' + (p.stock_quantity ?? 0) + '\n' + (p.description || ''));
    })
    .catch(error => toast(error.message, 'error'));
}

document.addEventListener('DOMContentLoaded', () => {
  attachEvents();
  refreshProducts().catch(error => toast(error.message, 'error'));
});