/* FacTo - Module Fournisseurs : liste, creation, edition, suppression */

const supplierState = {
  suppliers: [],
  pagination: { page: 1, per_page: 15, total: 0, pages: 0 },
  filters: { q: '', page: 1 },
  editingId: null,
};

function formatCurrency(amount) {
  return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(Number(amount) || 0);
}

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
  const response = await fetch('backend/suppliers.php?action=' + path, {
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

async function refreshSuppliers() {
  try {
    const params = new URLSearchParams({
      q: supplierState.filters.q,
      page: String(supplierState.filters.page),
      per_page: String(supplierState.pagination.per_page || 15),
    });
    const data = await api('list&' + params.toString());
    supplierState.suppliers = data.suppliers || [];
    supplierState.pagination = data.pagination || supplierState.pagination;
  } catch (error) {
    toast(error.message, 'error');
    return;
  }
  renderTable();
  renderPagination();
  renderSearchClear();
}

function renderTable() {
  const tbody = document.getElementById('suppliersTableBody');
  if (!tbody) return;

  if (!supplierState.suppliers.length) {
    tbody.innerHTML = `
      <tr><td colspan="6" style="text-align:center;color:var(--gray-500);padding:40px 16px;">
        Aucun fournisseur ne correspond a votre recherche. <a href="#" onclick="openNewSupplier(event)" style="color:var(--primary);font-weight:600;">Creer un fournisseur</a>.
      </td></tr>`;
    return;
  }

  tbody.innerHTML = supplierState.suppliers.map(supplier => `
    <tr>
      <td>
        <div class="supplier-table-cell">
          <div class="company-avatar" style="background:var(--gray-200);color:var(--gray-700);">${esc(supplier.name.split(' ').map(w => w[0]).join('').toUpperCase().slice(0, 2))}</div>
          <span class="supplier-name">${esc(supplier.name || '')}</span>
        </div>
      </td>
      <td style="color:var(--gray-700);">${esc(supplier.contact_name || '-')}</td>
      <td style="color:var(--gray-700);">${esc(supplier.email || '-')}</td>
      <td style="color:var(--gray-700);">${esc(supplier.phone || '-')}</td>
      <td style="color:var(--gray-700);font-size:.85rem;max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${esc(supplier.address || '-')}</td>
      <td>
        <div class="invoice-table-actions">
          <button type="button" title="Modifier" data-edit="${supplier.id}"><i class="bi bi-pencil"></i></button>
          <button type="button" title="Supprimer" class="is-danger" data-del="${supplier.id}"><i class="bi bi-trash"></i></button>
        </div>
      </td>
    </tr>
  `).join('');
}

function renderSearchClear() {
  const info = document.getElementById('supplierTableInfo');
  if (!info) return;
  const total = supplierState.pagination.total;
  info.textContent = total === 0 ? 'Aucun fournisseur' : (total + ' fournisseur' + (total > 1 ? 's' : '') + ' trouve' + (total > 1 ? 's' : ''));
}

function renderPagination() {
  const nav = document.getElementById('supplierPagination');
  const p = supplierState.pagination;
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

function openNewSupplier(event) {
  if (event) event.preventDefault();
  supplierState.editingId = null;
  document.getElementById('supplierModalTitle').textContent = 'Nouveau fournisseur';
  document.getElementById('supplierId').value = '';
  document.getElementById('supplierForm').reset();
  openModal('supplierModal');
}

function openEditSupplier(supplierId) {
  api('get&id=' + supplierId)
    .then(data => {
      const s = data.supplier;
      supplierState.editingId = s.id;
      document.getElementById('supplierModalTitle').textContent = 'Modifier ' + s.name;
      document.getElementById('supplierId').value = s.id;
      document.getElementById('supplierName').value = s.name || '';
      document.getElementById('supplierContact').value = s.contact_name || '';
      document.getElementById('supplierEmail').value = s.email || '';
      document.getElementById('supplierPhone').value = s.phone || '';
      document.getElementById('supplierAddress').value = s.address || '';
      openModal('supplierModal');
    })
    .catch(error => toast(error.message, 'error'));
}

async function submitSupplier(event) {
  event.preventDefault();
  const saveBtn = document.getElementById('btnSaveSupplier');
  saveBtn.disabled = true;

  const payload = {
    id: supplierState.editingId,
    name: document.getElementById('supplierName').value.trim(),
    contact_name: document.getElementById('supplierContact').value.trim(),
    email: document.getElementById('supplierEmail').value.trim(),
    phone: document.getElementById('supplierPhone').value.trim(),
    address: document.getElementById('supplierAddress').value.trim(),
  };

  try {
    const data = await api(supplierState.editingId ? 'update' : 'create', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
    toast(data.message || 'Fournisseur enregistre', 'success');
    closeModal('supplierModal');
    await refreshSuppliers();
  } catch (error) {
    toast(error.message, 'error');
  } finally {
    saveBtn.disabled = false;
  }
}

async function deleteSupplier(supplierId) {
  const supplier = supplierState.suppliers.find(s => String(s.id) === String(supplierId));
  const ok = await uiConfirm({
    title: 'Supprimer le fournisseur ?',
    text: 'Le fournisseur ' + (supplier ? supplier.name : '') + ' sera supprime.',
    confirmText: 'Supprimer',
    icon: 'bi-trash',
    danger: true,
  });
  if (!ok) return;
  try {
    const data = await api('delete', { method: 'POST', body: JSON.stringify({ id: supplierId }) });
    toast(data.message || 'Fournisseur supprime', 'success');
    await refreshSuppliers();
  } catch (error) {
    toast(error.message, 'error');
  }
}

function attachEvents() {
  document.getElementById('btnNewSupplier').addEventListener('click', openNewSupplier);
  document.getElementById('supplierForm').addEventListener('submit', submitSupplier);

  document.getElementById('suppliersTableBody').addEventListener('click', event => {
    const editBtn = event.target.closest('[data-edit]');
    const delBtn = event.target.closest('[data-del]');
    if (editBtn) openEditSupplier(editBtn.getAttribute('data-edit'));
    else if (delBtn) deleteSupplier(delBtn.getAttribute('data-del'));
  });

  document.getElementById('supplierPagination').addEventListener('click', event => {
    const btn = event.target.closest('[data-page]');
    if (!btn || btn.classList.contains('disabled')) return;
    supplierState.filters.page = Number(btn.getAttribute('data-page'));
    refreshSuppliers().catch(error => toast(error.message, 'error'));
  });

  const searchInput = document.getElementById('supplierFilterSearch');
  const topSearch = document.getElementById('supplierSearchInput');
  function onSearch() {
    const val = (searchInput ? searchInput.value : '') || (topSearch ? topSearch.value : '');
    supplierState.filters.q = val.trim();
    supplierState.filters.page = 1;
    refreshSuppliers().catch(error => toast(error.message, 'error'));
  }
  if (searchInput) {
    let timer;
    searchInput.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(onSearch, 350); });
  }
  if (topSearch) {
    let timer2;
    topSearch.addEventListener('input', () => { clearTimeout(timer2); timer2 = setTimeout(onSearch, 350); });
  }

  document.getElementById('btnRefreshSuppliers').addEventListener('click', () => {
    refreshSuppliers().catch(error => toast(error.message, 'error'));
  });
}

document.addEventListener('DOMContentLoaded', () => {
  attachEvents();
  refreshSuppliers().catch(error => toast(error.message, 'error'));
});