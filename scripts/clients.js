/* FacTo - Module Clients : liste, creation, edition, suppression */

const clientState = {
  clients: [],
  stats: null,
  pagination: { page: 1, per_page: 15, total: 0, pages: 0 },
  filters: { q: '', page: 1 },
  editingId: null,
};

function formatCurrency(amount) {
  return window.formatMoney ? window.formatMoney(amount) : new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(Number(amount) || 0);
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
  const response = await fetch('backend/clients.php?action=' + path, {
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

function avatar(name) {
  return name ? name.split(/\s+/).slice(0, 2).map(p => p[0]).join('').toUpperCase() : '??';
}

function getModal(id) {
  const el = document.getElementById(id);
  return el ? bootstrap.Modal.getOrCreateInstance(el) : null;
}

async function refreshClients() {
  try {
    const params = new URLSearchParams({
      q: clientState.filters.q,
      page: String(clientState.filters.page),
      per_page: String(clientState.pagination.per_page || 15),
    });
    const data = await api('list&' + params.toString());
    clientState.clients = data.clients || [];
    clientState.stats = data.stats || null;
    clientState.pagination = data.pagination || clientState.pagination;
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
  const container = document.getElementById('clientStatsCards');
  if (!container) return;
  const s = clientState.stats || { total: 0, with_invoices: 0, total_billed: 0 };
  const without = Math.max(0, (s.total || 0) - (s.with_invoices || 0));

  const cards = [
    { label: 'Clients au total', value: String(s.total), icon: 'bi-people', color: 'var(--info)', change: 'clients enregistres' },
    { label: 'Clients liés à des factures', value: String(s.with_invoices), icon: 'bi-person-badge', color: 'var(--primary)', change: 'clients actifs' },
    { label: 'Total facturé', value: formatCurrency(s.total_billed), icon: 'bi-wallet2', color: 'var(--success)', change: 'factures payees' },
    { label: 'Clients sans facture', value: String(without), icon: 'bi-person-circle', color: 'var(--gray-400)', change: 'a relancer' },
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
  const tbody = document.getElementById('clientsTableBody');
  if (!tbody) return;

  if (!clientState.clients.length) {
    tbody.innerHTML = `
      <tr><td colspan="7" style="text-align:center;color:var(--gray-500);padding:40px 16px;">
        Aucun client ne correspond a votre recherche. <a href="#" onclick="openNewClient(event)" style="color:var(--primary);font-weight:600;">Creer un client</a>.
      </td></tr>`;
    return;
  }

  tbody.innerHTML = clientState.clients.map(client => {
    const sub = client.company ? client.name : '';
    return `
      <tr>
        <td>
          <div class="client-table-cell">
            <div class="company-avatar" style="background:var(--gray-200);color:var(--gray-700);">${avatar(client.name)}</div>
            <div>
              <span class="client-name">${esc(client.name || '')}</span>
              ${sub ? `<span class="client-company">${esc(sub)}</span>` : ''}
            </div>
          </div>
        </td>
        <td style="color:var(--gray-700);">${esc(client.email || '') || '&mdash;'}</td>
        <td style="color:var(--gray-700);">${esc(client.phone || '') || '&mdash;'}</td>
        <td style="color:var(--gray-700);font-size:.85rem;">${esc(client.city || '')}${client.city && client.country ? ' / ' : ''}${esc(client.country || '') || '&mdash;'}</td>
        <td style="font-weight:600;color:var(--primary);">${client.invoices_count || 0}</td>
        <td class="client-total">${formatCurrency(client.total_billed)}</td>
        <td>
          <div class="invoice-table-actions">
            <button type="button" title="Voir" data-view="${client.id}"><i class="bi bi-eye"></i></button>
            <button type="button" title="Modifier" data-edit="${client.id}"><i class="bi bi-pencil"></i></button>
            <button type="button" title="Supprimer" class="is-danger" data-del="${client.id}"><i class="bi bi-trash"></i></button>
          </div>
        </td>
      </tr>`;
  }).join('');
}

function renderSearchClear() {
  const info = document.getElementById('clientTableInfo');
  if (!info) return;
  const total = clientState.pagination.total;
  info.textContent = total === 0
    ? 'Aucun client'
    : (total + ' client' + (total > 1 ? 's' : '') + ' trouve' + (total > 1 ? 's' : ''));
}

function renderPagination() {
  const nav = document.getElementById('clientPagination');
  const p = clientState.pagination;
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

function openNewClient(event) {
  if (event) event.preventDefault();
  clientState.editingId = null;
  document.getElementById('clientModalTitle').textContent = 'Nouveau client';
  document.getElementById('clientId').value = '';
  document.getElementById('clientForm').reset();
  openModal('clientModal');
}

function openEditClient(clientId) {
  api('get&id=' + clientId)
    .then(data => {
      const c = data.client;
      clientState.editingId = c.id;
      document.getElementById('clientModalTitle').textContent = 'Modifier ' + c.name;
      document.getElementById('clientId').value = c.id;
      document.getElementById('clientName').value = c.name || '';
      document.getElementById('clientCompany').value = c.company || '';
      document.getElementById('clientEmail').value = c.email || '';
      document.getElementById('clientPhone').value = c.phone || '';
      document.getElementById('clientAddress').value = c.address || '';
      document.getElementById('clientCity').value = c.city || '';
      document.getElementById('clientZip').value = c.zip || '';
      document.getElementById('clientCountry').value = c.country || '';
      openModal('clientModal');
    })
    .catch(error => toast(error.message, 'error'));
}

async function submitClient(event) {
  event.preventDefault();
  const saveBtn = document.getElementById('btnSaveClient');
  saveBtn.disabled = true;

  const payload = {
    id: clientState.editingId,
    name: document.getElementById('clientName').value.trim(),
    company: document.getElementById('clientCompany').value.trim(),
    email: document.getElementById('clientEmail').value.trim(),
    phone: document.getElementById('clientPhone').value.trim(),
    address: document.getElementById('clientAddress').value.trim(),
    city: document.getElementById('clientCity').value.trim(),
    zip: document.getElementById('clientZip').value.trim(),
    country: document.getElementById('clientCountry').value.trim(),
  };

  try {
    const data = await api(clientState.editingId ? 'update' : 'create', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
    toast(data.message || 'Client enregistre', 'success');
    closeModal('clientModal');
    await refreshClients();
  } catch (error) {
    toast(error.message, 'error');
  } finally {
    saveBtn.disabled = false;
  }
}

async function deleteClient(clientId) {
  const client = clientState.clients.find(c => String(c.id) === String(clientId));
  const ok = await uiConfirm({
    title: 'Supprimer le client ?',
    text: 'Les factures liées a ce client seront conservees (le lien sera dissocie). Cette action est irreversible.',
    confirmText: 'Supprimer',
    icon: 'bi-trash',
    danger: true,
  });
  if (!ok) return;
  try {
    const data = await api('delete', { method: 'POST', body: JSON.stringify({ id: clientId }) });
    toast(data.message || 'Client supprime', 'success');
    await refreshClients();
  } catch (error) {
    toast(error.message, 'error');
  }
}

function viewClient(clientId) {
  api('get&id=' + clientId)
    .then(data => {
      const c = data.client;
      alert('Client : ' + c.name + '\n' + (c.email || '') + '\nFactures : ' + c.invoices_count + '\nTotal facture : ' + formatCurrency(c.total_billed));
    })
    .catch(error => toast(error.message, 'error'));
}

function attachEvents() {
  document.getElementById('btnNewClient').addEventListener('click', openNewClient);
  document.getElementById('clientForm').addEventListener('submit', submitClient);

  document.getElementById('clientsTableBody').addEventListener('click', event => {
    const viewBtn = event.target.closest('[data-view]');
    const editBtn = event.target.closest('[data-edit]');
    const delBtn = event.target.closest('[data-del]');
    if (viewBtn) viewClient(viewBtn.getAttribute('data-view'));
    else if (editBtn) openEditClient(editBtn.getAttribute('data-edit'));
    else if (delBtn) deleteClient(delBtn.getAttribute('data-del'));
  });

  document.getElementById('clientPagination').addEventListener('click', event => {
    const btn = event.target.closest('[data-page]');
    if (!btn || btn.classList.contains('disabled')) return;
    clientState.filters.page = Number(btn.getAttribute('data-page'));
    refreshClients().catch(error => toast(error.message, 'error'));
  });

  const searchInput = document.getElementById('clientFilterSearch');
  const topSearch = document.getElementById('clientSearchInput');
  function onSearch() {
    const val = (searchInput ? searchInput.value : '') || (topSearch ? topSearch.value : '');
    clientState.filters.q = val.trim();
    clientState.filters.page = 1;
    refreshClients().catch(error => toast(error.message, 'error'));
  }
  if (searchInput) {
    let timer;
    searchInput.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(onSearch, 350); });
  }
  if (topSearch) {
    let timer2;
    topSearch.addEventListener('input', () => { clearTimeout(timer2); timer2 = setTimeout(onSearch, 350); });
  }
  document.getElementById('btnRefreshClients').addEventListener('click', () => refreshClients().catch(error => toast(error.message, 'error')));
}

document.addEventListener('DOMContentLoaded', () => {
  attachEvents();
  refreshClients().catch(error => toast(error.message, 'error'));
});
