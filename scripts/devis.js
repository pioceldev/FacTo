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
  issuer: null,
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

/* ============ DOCUMENT DEVIS (format « modèle » violet, partagé impression/aperçu) ============ */
async function loadIssuer() {
  try {
    const res = await fetch('backend/profile.php?action=get', { credentials: 'same-origin' });
    if (!res.ok) return;
    const data = await res.json();
    quoteState.issuer = data.user || null;
  } catch (e) { /* silencieux */ }
}

function quoteDocHTML(quote) {
  const u = quoteState.issuer || {};
  const company = (u.company || u.name || 'FacTo');
  const initial = (company.trim().charAt(0) || 'F').toUpperCase();
  const logo = u.logo_path
    ? `<img src="${esc(u.logo_path)}" alt="Logo">`
    : `<span>${esc(initial)}</span>`;

  const office = [
    `<p><strong>${esc(company)}</strong></p>`,
    u.phone ? `<p>${esc(u.phone)}</p>` : '',
    u.email ? `<p>${esc(u.email)}</p>` : '',
  ].join('');

  const clientBlock = [
    quote.client_name ? `<p><strong>${esc(quote.client_name)}</strong></p>` : '',
    quote.client_email ? `<p>${esc(quote.client_email)}</p>` : '',
    quote.client_phone ? `<p>${esc(quote.client_phone)}</p>` : '',
    quote.client_address ? `<p>${esc(quote.client_address)}</p>` : '',
  ].join('') || '<p>—</p>';

  const rows = (quote.items || []).map(item => `
    <tr>
      <td>${esc(item.description || '—')}</td>
      <td>${formatCurrency(item.unit_price)}</td>
      <td>${esc(item.quantity)}</td>
      <td>${esc(item.vat_rate)} %</td>
      <td>${formatCurrency(item.line_total)}</td>
    </tr>`).join('')
    || '<tr><td colspan="5" style="text-align:center;color:var(--gray-500);padding:14px;">Aucune ligne</td></tr>';

  const sum = {
    amount: Number(quote.amount) || 0,
    vat: Number(quote.vat) || 0,
    total: Number(quote.total) || 0,
  };

  const statusLabelMap = {
    brouillon: 'Brouillon',
    envoye: 'Envoyé',
    envoyé: 'Envoyé',
    accepte: 'Accepté',
    accepté: 'Accepté',
    refuse: 'Refusé',
    refusé: 'Refusé',
  };
  const statusText = statusLabelMap[String(quote.status || '').toLowerCase()] || esc(String(quote.status || '—'));

  const sign = `
    <div class="inv-doc__sign">
      ${u.signature ? `<img src="${esc(u.signature)}" alt="Signature de ${esc(u.name || company)}">` : ''}
      <div class="inv-doc__sign-name">${esc(u.name || company)}</div>
      <div class="inv-doc__sign-role">${u.signature ? 'Signature' : 'Non signé'}</div>
    </div>`;

  return `
    <div class="inv-doc">
      <div class="inv-doc__header">
        <div class="inv-doc__brand">
          <div class="inv-doc__logo">${logo}</div>
          <div>
            <div class="inv-doc__company">${esc(company)}</div>
            <div class="inv-doc__slogan">${esc(u.email || 'Facturation simplifiée')}</div>
          </div>
        </div>
        <div>
          <div class="inv-doc__doclabel">DEVIS</div>
          <div class="inv-doc__meta">
            <div>N° <strong>${esc(quote.ref || '—')}</strong></div>
            <div>${formatDate(quote.created_at)}</div>
            <div>Valide jusqu'au ${formatDate(quote.valid_until)}</div>
            <div>Statut : ${esc(statusText)}</div>
          </div>
        </div>
      </div>

      <div class="inv-doc__addresses">
        <div>
          <div class="inv-doc__block-title">Adresse / Émetteur</div>
          ${office}
        </div>
        <div>
          <div class="inv-doc__block-title">Devis pour</div>
          ${clientBlock}
        </div>
      </div>

      <table class="inv-doc__table">
        <thead>
          <tr><th>Désignation</th><th>P.U. HT</th><th>Qté</th><th>TVA</th><th>Total HT</th></tr>
        </thead>
        <tbody>${rows}</tbody>
      </table>

      <div class="inv-doc__sum">
        <div class="inv-doc__note">
          <div class="inv-doc__block-title">Note</div>
          <p>${esc(quote.notes || 'Merci de votre confiance. Ce devis est valable jusqu\'à la date de validité indiquée.')}</p>
        </div>
        <div class="inv-doc__totals">
          <div><span>Sous-total HT</span><span>${formatCurrency(sum.amount)}</span></div>
          <div><span>TVA</span><span>${formatCurrency(sum.vat)}</span></div>
          <div class="inv-grand"><span>Total TTC</span><span>${formatCurrency(sum.total)}</span></div>
        </div>
      </div>

      ${sign}

      <div class="inv-doc__thanks">Merci pour votre confiance</div>

      <div class="inv-doc__footer">
        <div>
          <b>Questions ?</b>
          <p>${esc(u.email || '—')}</p>
          <p>${esc(u.phone || '—')}</p>
        </div>
        <div>
          <b>Devis</b>
          <p>Réf. ${esc(quote.ref || '—')}</p>
          <p>Validité : ${formatDate(quote.valid_until)}</p>
        </div>
        <div>
          <b>Conditions</b>
          <p>${esc(quote.notes || 'Merci de valider ce devis avant la date de validité indiquée.')}</p>
        </div>
      </div>
    </div>`;
}

async function printQuote(quoteId) {
  try {
    if (!quoteState.issuer) await loadIssuer();
    const data = await api('get&id=' + quoteId);
    const quote = data.quote;
    const printArea = document.getElementById('printArea');
    printArea.innerHTML = quoteDocHTML(quote);
    setTimeout(() => window.print(), 60);
  } catch (error) {
    toast(error.message, 'error');
  }
}

/* Normalisation des statuts (avec ou sans accents) */
function normalizeStatus(s) {
  return String(s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
}

function statusBadge(status) {
  const s = normalizeStatus(status);
  const map = {
    brouillon: ['badge-soft-secondary', 'Brouillon'],
    envoye: ['badge-soft-info', 'Envoyé'],
    accepte: ['badge-soft-success', 'Accepté'],
    refuse: ['badge-soft-danger', 'Refusé'],
  };
  const m = map[s] || ['badge-soft-secondary', status || '—'];
  return `<span class="badge-soft ${m[0]}">${esc(m[1])}</span>`;
}

function formatDate(value) {
  if (!value) return '—';
  try {
    return new Date(value).toLocaleDateString('fr-FR');
  } catch (e) {
    return value;
  }
}

function renderTable() {
  const tbody = document.getElementById('quotesTableBody');
  if (!tbody) return;

  if (!quoteState.quotes.length) {
    tbody.innerHTML = `
      <tr><td colspan="8" style="text-align:center;color:var(--gray-500);padding:40px 16px;">
        Aucun devis trouvé. <a href="#" onclick="openNewQuote(event)" style="color:var(--primary);font-weight:600;">Créer votre premier devis</a>.
      </td></tr>`;
    return;
  }

  tbody.innerHTML = quoteState.quotes.map(quote => `
    <tr>
      <td style="font-weight:600;color:var(--gray-800);">${esc(quote.ref || '—')}</td>
      <td style="font-weight:500;">${esc(quote.client_name || 'Client ponctuel')}</td>
      <td>${formatCurrency(quote.amount)}</td>
      <td>${formatCurrency(quote.vat)}</td>
      <td style="font-weight:700;">${formatCurrency(quote.total)}</td>
      <td style="color:var(--gray-500);">${formatDate(quote.valid_until)}</td>
      <td>${statusBadge(quote.status)}</td>
      <td>
        <div class="invoice-table-actions" style="justify-content:flex-end;">
          <button type="button" title="Imprimer" data-print="${quote.id}"><i class="bi bi-printer"></i></button>
          <button type="button" title="Modifier" data-edit="${quote.id}"><i class="bi bi-pencil"></i></button>
          <button type="button" title="Supprimer" class="is-danger" data-del="${quote.id}"><i class="bi bi-trash"></i></button>
        </div>
      </td>
    </tr>`).join('');
}

function renderPagination() {
  const nav = document.getElementById('quotePagination');
  const info = document.getElementById('quoteTableInfo');
  const p = quoteState.pagination;

  if (info) {
    const start = p.total === 0 ? 0 : (p.page - 1) * p.per_page + 1;
    const end = Math.min(p.total, p.page * p.per_page);
    info.textContent = `${start}–${end} sur ${p.total} devis`;
  }
  if (!nav) return;

  if (p.pages <= 1) {
    nav.innerHTML = '';
    return;
  }

  const pages = [];
  for (let i = 1; i <= p.pages; i++) {
    if (p.pages > 7 && i > 2 && i < p.pages - 1 && Math.abs(i - p.page) > 1) {
      if (pages[pages.length - 1] !== '…') pages.push('…');
      continue;
    }
    pages.push(i);
  }

  const prevDisabled = p.page <= 1 ? ' disabled' : '';
  const nextDisabled = p.page >= p.pages ? ' disabled' : '';

  nav.innerHTML = `
    <button type="button" class="pagination-custom__link ${prevDisabled}" data-page="${p.page - 1}"><i class="bi bi-chevron-left"></i></button>
    ${pages.map(pg => pg === '…'
      ? '<span class="pagination-custom__link disabled">…</span>'
      : `<button type="button" class="pagination-custom__link ${pg === p.page ? 'active' : ''}" data-page="${pg}">${pg}</button>`
    ).join('')}
    <button type="button" class="pagination-custom__link ${nextDisabled}" data-page="${p.page + 1}"><i class="bi bi-chevron-right"></i></button>
  `;
}

function renderSearchClear() {
  const topSearch = document.getElementById('quoteFilterSearch');
  const bottomSearch = document.getElementById('quoteSearchInput');
  const active = quoteState.filters.q || '';

  [topSearch, bottomSearch].forEach(input => {
    if (!input) return;
    if (document.activeElement !== input) input.value = active;
  });
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

  // Recherche basse (filtre principal de la carte)
  const bottomSearch = document.getElementById('quoteSearchInput');
  if (bottomSearch) {
    let timer;
    bottomSearch.addEventListener('input', () => {
      clearTimeout(timer);
      timer = setTimeout(() => {
        quoteState.filters.q = bottomSearch.value.trim();
        quoteState.filters.page = 1;
        refreshQuotes().catch(error => toast(error.message, 'error'));
      }, 350);
    });
  }

  document.getElementById('quotesTableBody').addEventListener('click', event => {
    const printBtn = event.target.closest('[data-print]');
    const editBtn = event.target.closest('[data-edit]');
    const delBtn = event.target.closest('[data-del]');
    if (printBtn) printQuote(printBtn.getAttribute('data-print'));
    else if (editBtn) openEditQuote(editBtn.getAttribute('data-edit'));
    else if (delBtn) deleteQuote(delBtn.getAttribute('data-del'));
  });

  // Bouton d'en-tête : imprime le premier devis de la liste
  const headerPrintBtn = document.getElementById('btnPrintQuote');
  if (headerPrintBtn) {
    headerPrintBtn.addEventListener('click', () => {
      const first = quoteState.quotes[0];
      if (!first) {
        toast('Aucun devis à imprimer.', 'info');
        return;
      }
      printQuote(first.id);
    });
  }

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
  loadIssuer().catch(() => {});
  refreshQuotes().catch(error => toast(error.message, 'error'));
});