/* FacTo - Module Paiements : enregistrement, suivi */

const paymentState = {
  payments: [],
  pagination: { page: 1, per_page: 15, total: 0, pages: 0 },
  filters: { q: '', method: '', status: '', page: 1 },
  editingId: null,
  invoices: [],
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
  const response = await fetch('backend/payments.php?action=' + path, {
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
  return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(Number(amount) || 0);
}

async function refreshPayments() {
  try {
    const params = new URLSearchParams({
      q: paymentState.filters.q,
      method: paymentState.filters.method,
      status: paymentState.filters.status,
      page: String(paymentState.filters.page),
      per_page: String(paymentState.pagination.per_page || 15),
    });
    const data = await api('list&' + params.toString());
    paymentState.payments = data.payments || [];
    paymentState.pagination = data.pagination || paymentState.pagination;
    paymentState.stats = data.stats || null;
  } catch (error) {
    toast(error.message, 'error');
    return;
  }
  renderTable();
  renderPagination();
  renderStats();
  renderSearchClear();
}

async function loadInvoices() {
  try {
    const data = await api('invoices');
    paymentState.invoices = data.invoices || [];
    const select = document.getElementById('paymentInvoice');
    if (!select) return;
    select.innerHTML = '<option value="">-- Sélectionner --</option>' +
      paymentState.invoices.map(inv =>
        `<option value="${inv.id}">${esc(inv.ref)} — ${esc(inv.client_name || '')} (${formatCurrency(Number(inv.total))})</option>`
      ).join('');
  } catch (error) {
    toast('Impossible de charger la liste des factures', 'error');
  }
}

function renderStats() {
  const container = document.getElementById('paymentStatsCards');
  if (!container) return;
  const payments = paymentState.payments;
  const stats = paymentState.stats || {};
  const totalReceived = stats.total_received != null ? Number(stats.total_received) : payments.reduce((sum, p) => sum + (p.status === 'paid' ? Number(p.amount) : 0), 0);
  const pendingCount = stats.pending_count != null ? Number(stats.pending_count) : payments.filter(p => p.status === 'pending').length;
  const count = stats.count != null ? Number(stats.count) : (paymentState.pagination.total || payments.length);

  const cards = [
    { label: 'Total encaissé', value: formatCurrency(totalReceived), change: 'paiements payés', variant: 'up', icon: 'bi-credit-card', gradient: 'linear-gradient(135deg, #10b981, #34d399)' },
    { label: 'En attente', value: String(pendingCount), change: pendingCount > 0 ? 'à traiter' : 'aucun en attente', variant: pendingCount > 0 ? 'down' : 'up', icon: 'bi-hourglass-split', gradient: 'linear-gradient(135deg, #f59e0b, #fbbf24)' },
    { label: 'Paiements enregistrés', value: String(count), change: 'au total', variant: 'up', icon: 'bi-receipt', gradient: 'linear-gradient(135deg, #3b82f6, #60a5fa)' },
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
  const tbody = document.getElementById('paymentsTableBody');
  if (!tbody) return;

  if (!paymentState.payments.length) {
    tbody.innerHTML = `
      <tr><td colspan="8" style="text-align:center;color:var(--gray-500);padding:40px 16px;">
        Aucun paiement ne correspond a votre recherche. <a href="#" onclick="openNewPayment(event)" style="color:var(--primary);font-weight:600;">Enregistrer un paiement</a>.
      </td></tr>`;
    return;
  }

  tbody.innerHTML = paymentState.payments.map(payment => {
    const statusBadge = payment.status === 'paid' ? 'badge bg-success' : (payment.status === 'pending' ? 'badge bg-warning' : 'badge bg-danger');
    const statusText = payment.status === 'paid' ? 'Payé' : (payment.status === 'pending' ? 'En attente' : 'Échoué');
    return `
    <tr>
      <td style="color:var(--gray-700);">${esc(payment.created_at ? payment.created_at.slice(0, 10) : '-')}</td>
      <td style="color:var(--gray-700);">${esc(payment.invoice_ref || '-')}</td>
      <td style="color:var(--gray-700);">${esc(payment.client_name || '-')}</td>
      <td style="color:var(--gray-700);">${formatCurrency(Number(payment.amount))}</td>
      <td><span class="badge bg-secondary">${esc(payment.payment_method || '-')}</span></td>
      <td style="color:var(--gray-700);font-size:.85rem;">${esc(payment.transaction_ref || '-')}</td>
      <td><span class="${statusBadge}">${statusText}</span></td>
      <td>
        <div class="invoice-table-actions">
          <button type="button" title="Modifier" data-edit="${payment.id}"><i class="bi bi-pencil"></i></button>
          <button type="button" title="Supprimer" class="is-danger" data-del="${payment.id}"><i class="bi bi-trash"></i></button>
        </div>
      </td>
    </tr>`;
  }).join('');
}

function renderSearchClear() {
  const info = document.getElementById('paymentTableInfo');
  if (!info) return;
  const total = paymentState.pagination.total;
  info.textContent = total === 0 ? 'Aucun paiement' : (total + ' paiement' + (total > 1 ? 's' : '') + ' trouve' + (total > 1 ? 's' : ''));
}

function renderPagination() {
  const nav = document.getElementById('paymentPagination');
  const p = paymentState.pagination;
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

function openNewPayment(event) {
  if (event) event.preventDefault();
  paymentState.editingId = null;
  document.getElementById('paymentModalTitle').textContent = 'Enregistrer un paiement';
  document.getElementById('paymentId').value = '';
  document.getElementById('paymentForm').reset();
  loadInvoices().catch(() => {});
  openModal('paymentModal');
}

function openEditPayment(paymentId) {
  api('get&id=' + paymentId)
    .then(data => {
      const payment = data.payment;
      paymentState.editingId = payment.id;
      document.getElementById('paymentModalTitle').textContent = 'Modifier paiement';
      document.getElementById('paymentId').value = payment.id;
      document.getElementById('paymentInvoice').value = payment.invoice_id || '';
      document.getElementById('paymentAmount').value = payment.amount;
      document.getElementById('paymentMethod').value = payment.payment_method || 'virement';
      document.getElementById('paymentStatus').value = payment.status || 'paid';
      document.getElementById('paymentTxRef').value = payment.transaction_ref || '';
      document.getElementById('paymentPaidAt').value = payment.paid_at ? payment.paid_at.slice(0, 10) : '';
      loadInvoices().catch(() => {});
      openModal('paymentModal');
    })
    .catch(error => toast(error.message, 'error'));
}

async function submitPayment(event) {
  event.preventDefault();
  const saveBtn = document.getElementById('btnSavePayment');
  saveBtn.disabled = true;

  const invoiceSelect = document.getElementById('paymentInvoice');
  const invoiceId = invoiceSelect.value ? parseInt(invoiceSelect.value, 10) : 0;

  const payload = {
    id: paymentState.editingId,
    invoice_id: invoiceId,
    amount: parseFloat(document.getElementById('paymentAmount').value) || 0,
    payment_method: document.getElementById('paymentMethod').value,
    status: document.getElementById('paymentStatus').value,
    transaction_ref: document.getElementById('paymentTxRef').value.trim(),
    paid_at: document.getElementById('paymentPaidAt').value || null,
  };

  try {
    const data = await api(paymentState.editingId ? 'update' : 'create', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
    toast(data.message || 'Paiement enregistré', 'success');
    closeModal('paymentModal');
    await refreshPayments();
  } catch (error) {
    toast(error.message, 'error');
  } finally {
    saveBtn.disabled = false;
  }
}

async function deletePayment(paymentId) {
  const payment = paymentState.payments.find(p => String(p.id) === String(paymentId));
  const ok = await uiConfirm({
    title: 'Supprimer le paiement ?',
    text: 'Le paiement de ' + formatCurrency(Number(payment?.amount || 0)) + ' sera supprimé.',
    confirmText: 'Supprimer',
    icon: 'bi-trash',
    danger: true,
  });
  if (!ok) return;
  try {
    const data = await api('delete', { method: 'POST', body: JSON.stringify({ id: paymentId }) });
    toast(data.message || 'Paiement supprimé', 'success');
    await refreshPayments();
  } catch (error) {
    toast(error.message, 'error');
  }
}

function attachEvents() {
  document.getElementById('btnNewPayment').addEventListener('click', openNewPayment);
  document.getElementById('paymentForm').addEventListener('submit', submitPayment);

  document.getElementById('paymentsTableBody').addEventListener('click', event => {
    const editBtn = event.target.closest('[data-edit]');
    const delBtn = event.target.closest('[data-del]');
    if (editBtn) openEditPayment(editBtn.getAttribute('data-edit'));
    else if (delBtn) deletePayment(delBtn.getAttribute('data-del'));
  });

  document.getElementById('paymentPagination').addEventListener('click', event => {
    const btn = event.target.closest('[data-page]');
    if (!btn || btn.classList.contains('disabled')) return;
    paymentState.filters.page = Number(btn.getAttribute('data-page'));
    refreshPayments().catch(error => toast(error.message, 'error'));
  });

  const searchInput = document.getElementById('paymentFilterSearch');
  if (searchInput) {
    let timer;
    searchInput.addEventListener('input', () => {
      clearTimeout(timer);
      timer = setTimeout(() => {
        paymentState.filters.q = searchInput.value.trim();
        paymentState.filters.page = 1;
        refreshPayments().catch(error => toast(error.message, 'error'));
      }, 350);
    });
  }

  const methodFilter = document.getElementById('paymentMethodFilter');
  if (methodFilter) {
    methodFilter.addEventListener('change', () => {
      paymentState.filters.method = methodFilter.value;
      paymentState.filters.page = 1;
      refreshPayments().catch(error => toast(error.message, 'error'));
    });
  }

  const statusFilter = document.getElementById('paymentStatusFilter');
  if (statusFilter) {
    statusFilter.addEventListener('change', () => {
      paymentState.filters.status = statusFilter.value;
      paymentState.filters.page = 1;
      refreshPayments().catch(error => toast(error.message, 'error'));
    });
  }

  document.getElementById('btnRefreshPayments').addEventListener('click', () => {
    refreshPayments().catch(error => toast(error.message, 'error'));
  });
}

document.addEventListener('DOMContentLoaded', () => {
  attachEvents();
  loadInvoices().catch(() => {});
  refreshPayments().catch(error => toast(error.message, 'error'));
});