/* FacTo - Module Devis : creation, edition, gestion des lignes */

const quoteState = {
  quotes: [],
  pagination: { page: 1, per_page: 15, total: 0, pages: 0 },
  filters: { q: '', status: '', period: '', page: 1 },
  editingId: null,
  clients: [],
  products: [],
  quoteItems: [],
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
  const response = await fetch('backend/quotes.php?action=' + path, {
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

async function refreshQuotes() {
  try {
    const params = new URLSearchParams({
      q: quoteState.filters.q,
      status: quoteState.filters.status,
      period: quoteState.filters.period,
      page: String(quoteState.filters.page),
      per_page: String(quoteState.pagination.per_page || 15),
    });
    const data = await api('list&' + params.toString());
    quoteState.quotes = data.quotes || [];
    quoteState.pagination = data.pagination || quoteState.pagination;
    quoteState.stats = data.stats || null;
  } catch (error) {
    toast(error.message, 'error');
    return;
  }
  renderTable();
  renderPagination();
  renderStats();
  renderSearchClear();
}

async function loadClients() {
  try {
    const data = await api('clients');
    quoteState.clients = data.clients || [];
    const select = document.getElementById('quoteClient');
    if (!select) return;
    select.innerHTML = '<option value="">Selectionner un client...</option>' +
      quoteState.clients.map(c =>
        `<option value="${c.id}">${esc(c.name)} (${esc(c.email || '')})</option>`
      ).join('');
  } catch (error) {
    toast('Impossible de charger la liste des clients', 'error');
  }
}

async function loadProducts() {
  try {
    const data = await api('products');
    quoteState.products = data.products || [];
  } catch (error) {
    toast('Impossible de charger la liste des produits', 'error');
  }
}

function renderStats() {
  const container = document.getElementById('quoteStatsCards');
  if (!container) return;
  const quotes = quoteState.quotes;
  const stats = quoteState.stats;

  // Les statuts peuvent être stockés avec ou sans accents ('envoye' ou 'envoyé')
  const normalize = s => (s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');

  let pending, accepted, total;
  if (stats) {
    pending = Number(stats.pending) || 0;
    accepted = Number(stats.accepted) || 0;
    total = Number(stats.total) || 0;
  } else {
    pending = quotes.filter(q => ['brouillon', 'envoye'].includes(normalize(q.status))).length;
    accepted = quotes.filter(q => normalize(q.status) === 'accepte').length;
    total = quotes.reduce((sum, q) => sum + Number(q.total || 0), 0);
  }

  const cards = [
    { label: 'Devis en attente', value: String(pending), change: 'brouillons & envoyés', variant: 'up', icon: 'bi-file-text', gradient: 'linear-gradient(135deg, #f59e0b, #fbbf24)' },
    { label: 'Devis acceptés', value: String(accepted), change: 'validés par les clients', variant: 'up', icon: 'bi-check-circle', gradient: 'linear-gradient(135deg, #10b981, #34d399)' },
    { label: 'Montant total', value: formatCurrency(total), change: 'tous les devis', variant: 'up', icon: 'bi-currency-euro', gradient: 'linear-gradient(135deg, #f97316, #fb923c)' },
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

function renderQuoteItems() {
  const container = document.getElementById('quoteItemsContainer');
  if (!container) return;

  if (!quoteState.quoteItems.length) {
    container.innerHTML = '<p style="color:var(--gray-500);font-size:.85rem;">Aucune ligne. Cliquez sur "Ajouter une ligne" pour commencer.</p>';
    return;
  }

  container.innerHTML = quoteState.quoteItems.map((item, index) => `
    <div class="row g-2 mb-2 align-items-end" data-item-index="${index}">
      <div class="col-md-3">
        <label class="form-label">Produit</label>
        <select class="form-select form-select-sm quote-item-product" data-index="${index}">
          <option value="">--</option>
          ${quoteState.products.map(p => `<option value="${p.id}" ${item.product_id == p.id ? 'selected' : ''}>${esc(p.name)} (${esc(p.sku)})</option>`).join('')}
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Description</label>
        <input type="text" class="form-control form-control-sm quote-item-desc" data-index="${index}" value="${esc(item.description || '')}" placeholder="Description">
      </div>
      <div class="col-md-1">
        <label class="form-label">Qté</label>
        <input type="number" class="form-control form-control-sm quote-item-qty" data-index="${index}" value="${item.quantity || 1}" min="0" step="0.01">
      </div>
      <div class="col-md-2">
        <label class="form-label">P.U.</label>
        <input type="number" class="form-control form-control-sm quote-item-price" data-index="${index}" value="${item.unit_price || 0}" min="0" step="0.01">
      </div>
      <div class="col-md-1">
        <label class="form-label">TVA %</label>
        <input type="number" class="form-control form-control-sm quote-item-vat" data-index="${index}" value="${item.vat_rate || 0}" min="0" step="0.01">
      </div>
      <div class="col-md-1">
        <label class="form-label">Total</label>
        <input type="text" class="form-control form-control-sm quote-item-total" data-index="${index}" value="${formatCurrency((item.quantity || 0) * (item.unit_price || 0))}" readonly style="background:var(--gray-100);">
      </div>
      <div class="col-md-1 d-flex align-items-end">
        <button type="button" class="btn btn-outline-danger btn-sm btn-remove-quote-item" data-index="${index}"><i class="bi bi-trash"></i></button>
      </div>
    </div>
  `).join('');
}

function recalcQuoteItemTotals() {
  quoteState.quoteItems.forEach((item, index) => {
    const qty = parseFloat(document.querySelector(`.quote-item-qty[data-index="${index}"]`)?.value || 0);
    const price = parseFloat(document.querySelector(`.quote-item-price[data-index="${index}"]`)?.value || 0);
    item.quantity = qty;
    item.unit_price = price;
    const total = qty * price;
    const totalInput = document.querySelector(`.quote-item-total[data-index="${index}"]`);
    if (totalInput) totalInput.value = formatCurrency(total);
  });
}

function openNewQuote(event) {
  if (event) event.preventDefault();
  quoteState.editingId = null;
  document.getElementById('quoteModalTitle').textContent = 'Nouveau devis';
  document.getElementById('quoteId').value = '';
  document.getElementById('quoteForm').reset();
  quoteState.quoteItems = [];
  renderQuoteItems();
  loadClients().catch(() => {});
  loadProducts().catch(() => {});
  openModal('quoteModal');
}

function openEditQuote(quoteId) {
  api('get&id=' + quoteId)
    .then(data => {
      const quote = data.quote;
      quoteState.editingId = quote.id;
      document.getElementById('quoteModalTitle').textContent = 'Modifier devis ' + quote.ref;
      document.getElementById('quoteId').value = quote.id;
      document.getElementById('quoteClient').value = quote.client_id || '';
      document.getElementById('quoteRef').value = quote.ref || '';
      document.getElementById('quoteStatus').value = quote.status || 'brouillon';
      document.getElementById('quoteValidUntil').value = quote.valid_until || '';
      quoteState.quoteItems = (quote.items || []).map(item => ({
        product_id: item.product_id,
        description: item.description,
        quantity: Number(item.quantity),
        unit_price: Number(item.unit_price),
        vat_rate: Number(item.vat_rate),
      }));
      renderQuoteItems();
      loadClients().catch(() => {});
      loadProducts().catch(() => {});
      openModal('quoteModal');
    })
    .catch(error => toast(error.message, 'error'));
}

async function submitQuote(event) {
  event.preventDefault();
  const saveBtn = document.getElementById('btnSaveQuote');
  saveBtn.disabled = true;

  const clientSelect = document.getElementById('quoteClient');
  const clientId = clientSelect.value ? parseInt(clientSelect.value, 10) : 0;

  const payload = {
    id: quoteState.editingId,
    client_id: clientId,
    ref: document.getElementById('quoteRef').value.trim(),
    status: document.getElementById('quoteStatus').value,
    valid_until: document.getElementById('quoteValidUntil').value || null,
    items: quoteState.quoteItems.map(item => ({
      product_id: item.product_id || 0,
      description: item.description || '',
      quantity: Number(item.quantity) || 0,
      unit_price: Number(item.unit_price) || 0,
      vat_rate: Number(item.vat_rate) || 0,
    })),
  };

  try {
    const data = await api(quoteState.editingId ? 'update' : 'create', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
    toast(data.message || 'Devis enregistré', 'success');
    closeModal('quoteModal');
    await refreshQuotes();
  } catch (error) {
    toast(error.message, 'error');
  } finally {
    saveBtn.disabled = false;
  }
}

async function deleteQuote(quoteId) {
  const quote = quoteState.quotes.find(q => String(q.id) === String(quoteId));
  const ok = await uiConfirm({
    title: 'Supprimer le devis ?',
    text: 'Le devis ' + (quote ? quote.ref : '') + ' sera supprimé.',
    confirmText: 'Supprimer',
    icon: 'bi-trash',
    danger: true,
  });
  if (!ok) return;
  try {
    const data = await api('delete', { method: 'POST', body: JSON.stringify({ id: quoteId }) });
    toast(data.message || 'Devis supprimé', 'success');
    await refreshQuotes();
  } catch (error) {
    toast(error.message, 'error');
  }
}

function getModal(id) {
  const el = document.getElementById(id);
  return el ? bootstrap.Modal.getOrCreateInstance(el) : null;
}

function attachEvents() {
  document.getElementById('btnNewQuote').addEventListener('click', openNewQuote);
  document.getElementById('quoteForm').addEventListener('submit', submitQuote);

  document.getElementById('quotesTableBody').addEventListener('click', event => {
    const editBtn = event.target.closest('[data-edit]');
    const delBtn = event.target.closest('[data-del]');
    if (editBtn) openEditQuote(editBtn.getAttribute('data-edit'));
    else if (delBtn) deleteQuote(delBtn.getAttribute('data-del'));
  });

  document.getElementById('quotePagination').addEventListener('click', event => {
    const btn = event.target.closest('[data-page]');
    if (!btn || btn.classList.contains('disabled')) return;
    quoteState.filters.page = Number(btn.getAttribute('data-page'));
    refreshQuotes().catch(error => toast(error.message, 'error'));
  });

  const searchInput = document.getElementById('quoteFilterSearch');
  if (searchInput) {
    let timer;
    searchInput.addEventListener('input', () => {
      clearTimeout(timer);
      timer = setTimeout(() => {
        quoteState.filters.q = searchInput.value.trim();
        quoteState.filters.page = 1;
        refreshQuotes().catch(error => toast(error.message, 'error'));
      }, 350);
    });
  }

  const statusFilter = document.getElementById('quoteStatusFilter');
  if (statusFilter) {
    statusFilter.addEventListener('change', () => {
      quoteState.filters.status = statusFilter.value;
      quoteState.filters.page = 1;
      refreshQuotes().catch(error => toast(error.message, 'error'));
    });
  }

  const periodFilter = document.getElementById('quotePeriodFilter');
  if (periodFilter) {
    periodFilter.addEventListener('change', () => {
      quoteState.filters.period = periodFilter.value;
      quoteState.filters.page = 1;
      refreshQuotes().catch(error => toast(error.message, 'error'));
    });
  }

  document.getElementById('btnRefreshQuotes').addEventListener('click', () => {
    refreshQuotes().catch(error => toast(error.message, 'error'));
  });

  document.getElementById('btnAddQuoteItem').addEventListener('click', () => {
    quoteState.quoteItems.push({ product_id: '', description: '', quantity: 1, unit_price: 0, vat_rate: 0 });
    renderQuoteItems();
    attachQuoteItemEvents();
  });

  document.getElementById('quoteItemsContainer').addEventListener('input', event => {
    const target = event.target;
    if (!target.classList.contains('quote-item-qty') && !target.classList.contains('quote-item-price') && !target.classList.contains('quote-item-vat')) return;
    const index = target.getAttribute('data-index');
    const qty = parseFloat(document.querySelector(`.quote-item-qty[data-index="${index}"]`)?.value || 0);
    const price = parseFloat(document.querySelector(`.quote-item-price[data-index="${index}"]`)?.value || 0);
    const total = qty * price;
    const totalInput = document.querySelector(`.quote-item-total[data-index="${index}"]`);
    if (totalInput) totalInput.value = formatCurrency(total);
  });

  document.getElementById('quoteItemsContainer').addEventListener('change', event => {
    const target = event.target;
    if (target.classList.contains('quote-item-product')) {
      const index = target.getAttribute('data-index');
      const productId = target.value;
      const product = quoteState.products.find(p => String(p.id) === productId);
      if (product) {
        const priceInput = document.querySelector(`.quote-item-price[data-index="${index}"]`);
        if (priceInput) priceInput.value = product.unit_price || 0;
        const qtyInput = document.querySelector(`.quote-item-qty[data-index="${index}"]`);
        if (qtyInput) qtyInput.dispatchEvent(new Event('input'));
      }
    }
  });

  document.getElementById('quoteItemsContainer').addEventListener('click', event => {
    const btn = event.target.closest('.btn-remove-quote-item');
    if (!btn) return;
    const index = parseInt(btn.getAttribute('data-index'), 10);
    quoteState.quoteItems.splice(index, 1);
    renderQuoteItems();
    attachQuoteItemEvents();
  });
}

function attachQuoteItemEvents() {
  document.querySelectorAll('.quote-item-product').forEach(el => {
    el.removeEventListener('change', attachQuoteItemEvents);
  });
}

document.addEventListener('DOMContentLoaded', () => {
  attachEvents();
  loadClients().catch(() => {});
  loadProducts().catch(() => {});
  refreshQuotes().catch(error => toast(error.message, 'error'));
});