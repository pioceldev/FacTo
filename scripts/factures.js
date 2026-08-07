/* FacTo — Module Factures : liste, création, édition, détail, impression */

const invoiceState = {
  invoices: [],
  clients: [],
  products: [],
  stats: null,
  usage: null,
  pagination: { page: 1, per_page: 15, total: 0, pages: 0 },
  filters: { status: 'all', q: '', client_id: '', page: 1 },
  editingId: null,
  editingItems: [],
  detail: null,
  issuer: null,
};

const STATUS_LABELS = {
  brouillon: 'Brouillon',
  en_attente: 'En attente',
  en_retard: 'En retard',
  payee: 'Payée',
  annulee: 'Annulée',
};

const STATUS_BADGES = {
  brouillon: 'badge-soft-secondary',
  en_attente: 'badge-soft-warning',
  en_retard: 'badge-soft-danger',
  payee: 'badge-soft-success',
  annulee: 'badge-soft-secondary',
};

function formatCurrency(amount) {
  return window.formatMoney ? window.formatMoney(amount) : new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(Number(amount) || 0);
}

function formatDate(value) {
  if (!value) return '-';
  try {
    return new Date(value).toLocaleDateString('fr-FR');
  } catch {
    return value;
  }
}

function statusLabel(status) {
  return STATUS_LABELS[status] || status || '-';
}

function statusBadge(status) {
  return STATUS_BADGES[status] || 'badge-soft-secondary';
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
  const response = await fetch('backend/invoices.php?action=' + path, {
    credentials: 'same-origin',
    headers,
    ...options,
  });

  if (response.status === 401) {
    window.location.href = 'connexion.html';
    throw new Error('Non autorisé');
  }

  let data = {};
  try {
    data = await response.json();
  } catch (e) {
    // réponse non JSON
  }

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

function buildListPath() {
  const f = invoiceState.filters;
  const params = new URLSearchParams();
  if (f.status && f.status !== 'all') params.set('status', f.status);
  if (f.q) params.set('q', f.q);
  if (f.client_id) params.set('client_id', f.client_id);
  params.set('page', f.page);
  params.set('per_page', invoiceState.pagination.per_page || 15);
  return 'list&' + params.toString();
}

async function loadList() {
  const data = await api(buildListPath());
  invoiceState.invoices = data.invoices || [];
  invoiceState.stats = data.stats || null;
  invoiceState.usage = data.usage || null;
  invoiceState.pagination = data.pagination || invoiceState.pagination;
}

async function loadSelectors() {
  const [clientsData, productsData] = await Promise.all([
    api('clients').catch(() => ({ clients: [] })),
    api('products').catch(() => ({ products: [] })),
  ]);
  invoiceState.clients = clientsData.clients || [];
  invoiceState.products = productsData.products || [];
}

async function refresh() {
  try {
    await Promise.all([loadList(), loadSelectors()]);
    renderStats();
    renderTable();
    renderPagination();
    renderQuota();
  } catch (error) {
    toast(error.message, 'error');
  }
}

/* ============ STATS ============ */
function renderStats() {
  const container = document.getElementById('invoiceStatsCards');
  if (!container) return;

  const s = invoiceState.stats || { monthly_revenue: 0, pending_amount: 0, counts: {} };
  const counts = s.counts || {};
  const totalEmitted = Object.values(counts).reduce((a, b) => a + b, 0);

  const cards = [
    { label: 'Encaissé (ce mois)', value: formatCurrency(s.monthly_revenue), icon: 'bi-wallet2', color: 'var(--success)' },
    { label: 'À encaisser', value: formatCurrency(s.pending_amount), icon: 'bi-hourglass-split', color: 'var(--warning)' },
    { label: 'En retard', value: String(counts.en_retard || 0), icon: 'bi-exclamation-triangle', color: 'var(--danger)' },
    { label: 'Total factures', value: String(totalEmitted), icon: 'bi-receipt', color: 'var(--primary)' },
  ];

  const animate = onceAnimated(container);

  container.innerHTML = cards.map((card, i) => `
    <div class="stat-card${animate}" style="animation-delay: ${i * 0.08}s">
      <div class="d-flex justify-content-between align-items-start">
        <div>
          <div class="stat-card__label">${card.label}</div>
          <div class="stat-card__value">${card.value}</div>
          <div class="stat-card__change up"><i class="bi bi-arrow-up-right"></i> en direct</div>
        </div>
        <div class="stat-icon" style="background:rgba(249,115,22,0.1);color:${card.color};"><i class="bi ${card.icon}"></i></div>
      </div>
    </div>
  `).join('');
}

/* ============ TABLE ============ */
function renderTable() {
  const tbody = document.getElementById('invoicesTableBody');
  if (!tbody) return;

  if (!invoiceState.invoices.length) {
    tbody.innerHTML = `
      <tr><td colspan="6" style="text-align:center;color:var(--gray-500);padding:40px 16px;">
        Aucune facture trouvée. <a href="#" onclick="openNewInvoice(event)" style="color:var(--primary);font-weight:600;">Créer votre première facture</a>.
      </td></tr>`;
    return;
  }

  tbody.innerHTML = invoiceState.invoices.map(invoice => {
    const isCancelled = invoice.status === 'annulee';
    const statusClass = STATUS_BADGES[invoice.status] || 'badge-soft-secondary';
    return `
      <tr>
        <td>
          <div style="font-weight:700;color:var(--gray-800);">${esc(invoice.ref || '-')}</div>
          <div style="font-size:.72rem;color:var(--gray-400);">Émise le ${formatDate(invoice.issued_at || invoice.created_at)}</div>
        </td>
        <td style="font-weight:500;">${esc(invoice.client_name || 'Client inconnu')}</td>
        <td style="color:var(--gray-500);white-space:nowrap;">${formatDate(invoice.due_date)}</td>
        <td style="font-weight:800;color:var(--gray-900);white-space:nowrap;">${formatCurrency(invoice.total)}</td>
        <td>
          <select class="invoice-status-select invoice-status-select--${invoice.status}" data-id="${invoice.id}" ${isCancelled ? 'disabled' : ''} title="Changer le statut">
            ${Object.keys(STATUS_LABELS).map(s =>
              `<option value="${s}" ${s === invoice.status ? 'selected' : ''}>${STATUS_LABELS[s]}</option>`
            ).join('')}
          </select>
        </td>
        <td>
          <div class="invoice-table-actions" style="justify-content:flex-end;">
            <button type="button" title="Voir" data-view="${invoice.id}"><i class="bi bi-eye"></i></button>
            <button type="button" title="Télécharger PDF" data-download="${invoice.id}"><i class="bi bi-download"></i></button>
            <button type="button" title="Modifier" data-edit="${invoice.id}" ${isCancelled ? 'disabled style="opacity:.4;cursor:not-allowed;"' : ''}><i class="bi bi-pencil"></i></button>
            <button type="button" title="Imprimer" data-print="${invoice.id}"><i class="bi bi-printer"></i></button>
            <button type="button" title="Supprimer" class="is-danger" data-del="${invoice.id}"><i class="bi bi-trash"></i></button>
          </div>
        </td>
      </tr>`;
  }).join('');
}

function esc(value) {
  return String(value == null ? '' : value)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function renderPagination() {
  const nav = document.getElementById('invoicePagination');
  const info = document.getElementById('invoiceTableInfo');
  const p = invoiceState.pagination;

  if (info) {
    const start = p.total === 0 ? 0 : (p.page - 1) * p.per_page + 1;
    const end = Math.min(p.total, p.page * p.per_page);
    info.textContent = `${start}–${end} sur ${p.total} facture${p.total > 1 ? 's' : ''}`;
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

function renderQuota() {
  const badge = document.getElementById('quotaBadge');
  const banner = document.getElementById('invoiceQuotaBanner');
  const usage = invoiceState.usage;

  if (!usage || !badge || !banner) return;

  const limit = usage.invoice_limit || 0;
  const count = usage.invoice_count || usage.invoice_count_month || 0;

  if (limit >= 999999) {
    badge.style.display = 'none';
    banner.style.display = 'none';
    return;
  }

  const remaining = Math.max(0, limit - count);
  badge.style.display = 'inline-flex';
  badge.className = 'badge-soft ' + (remaining === 0 ? 'badge-soft-danger' : remaining <= Math.ceil(limit * 0.2) ? 'badge-soft-warning' : 'badge-soft-secondary');
  badge.innerHTML = `<i class="bi bi-receipt"></i> ${count}/${limit} factures ce mois`;

  const pct = limit > 0 ? (count / limit) * 100 : 0;
  banner.style.display = remaining === 0 || pct >= 80 ? 'flex' : 'none';
  banner.innerHTML = remaining === 0
    ? `<i class="bi bi-exclamation-triangle-fill"></i> <span>Quota mensuel atteint (${limit} factures). Passez au plan supérieur pour continuer à facturer.</span> <a href="choix-abonnement.html">Passer au plan supérieur →</a>`
    : `<i class="bi bi-info-circle-fill"></i> <span>Quota de factures bientôt atteint : ${count}/${limit} factures ce mois.</span> <a href="choix-abonnement.html">Voir les offres →</a>`;
}

/* ============ ITEMS (éditeur) ============ */
function addItemRow(data) {
  const container = document.getElementById('invoiceItemsRows');
  if (!container) return;

  const row = document.createElement('div');
  row.className = 'invoice-item-row';

  const productOptions = invoiceState.products.map(p => {
    const stock = p.stock_quantity != null ? ` — Stock dispo : ${p.stock_quantity}` : '';
    return `<option value="${p.id}">${esc(p.name)}${stock}</option>`;
  }).join('');

  row.innerHTML = `
    <div class="invoice-item-row__product-wrap">
      <select class="invoice-item-row__product">
        <option value="">— Produit / direct —</option>
        ${productOptions}
      </select>
      <small class="invoice-item-row__stock"></small>
    </div>
    <input class="invoice-item-row__desc" type="text" placeholder="Désignation">
    <input class="invoice-item-row__qty" type="number" min="0" step="0.01" value="1">
    <input class="invoice-item-row__price" type="number" min="0" step="0.01" value="0">
    <input class="invoice-item-row__vat" type="number" min="0" step="0.01" value="0">
    <span class="invoice-item-row__total">0,00 €</span>
    <button type="button" class="invoice-item-row__remove" title="Supprimer la ligne"><i class="bi bi-x-lg"></i></button>
  `;

  if (data) {
    if (data.product_id) {
      const opt = productOptions.includes(`value="${data.product_id}"`);
      row.querySelector('.invoice-item-row__product').value = data.product_id || '';
    }
    row.querySelector('.invoice-item-row__desc').value = data.description || '';
    row.querySelector('.invoice-item-row__qty').value = data.quantity ?? 1;
    row.querySelector('.invoice-item-row__price').value = data.unit_price || 0;
    row.querySelector('.invoice-item-row__vat').value = data.vat_rate || 0;
  }

  container.appendChild(row);
  updateRowStockInfo(row);
  recalcTotals();
}

/* Affiche le stock disponible du produit sélectionné sous le sélecteur */
function updateRowStockInfo(row) {
  const select = row.querySelector('.invoice-item-row__product');
  const badge = row.querySelector('.invoice-item-row__stock');
  if (!select || !badge) return;
  const productId = select.value;
  const qty = parseFloat(row.querySelector('.invoice-item-row__qty').value) || 0;
  const product = invoiceState.products.find(p => String(p.id) === productId);

  if (!product) {
    badge.textContent = '';
    badge.className = 'invoice-item-row__stock';
    return;
  }
  let available = product.stock_quantity != null ? Number(product.stock_quantity) : null;
  if (available == null) {
    badge.textContent = '';
    badge.className = 'invoice-item-row__stock';
    return;
  }
  // En édition, la facture courante a déjà réservé ses quantités : on les
  // réintègre pour afficher le stock réellement utilisable.
  available += ownReservedQty(productId);

  const over = qty > available;
  badge.textContent = `Stock dispo : ${available}${over ? ` — dépassé de ${Math.max(0, qty - available)} !` : ''}`;
  badge.className = 'invoice-item-row__stock' + (over ? ' is-over' : ' is-ok');
}

function collectItems() {
  const rows = document.querySelectorAll('#invoiceItemsRows .invoice-item-row');
  const items = [];
  rows.forEach(row => {
    const description = row.querySelector('.invoice-item-row__desc').value.trim();
    const quantity = parseFloat(row.querySelector('.invoice-item-row__qty').value) || 0;
    const unitPrice = parseFloat(row.querySelector('.invoice-item-row__price').value) || 0;
    const vatRate = parseFloat(row.querySelector('.invoice-item-row__vat').value) || 0;

    if (!description && quantity <= 0 && unitPrice <= 0 && vatRate <= 0) return;

    const productId = row.querySelector('.invoice-item-row__product').value;
    items.push({
      product_id: productId ? Number(productId) : null,
      description,
      quantity,
      unit_price: unitPrice,
      vat_rate: vatRate,
    });
  });
  return items;
}

/* Quantité déjà réservée par la facture en cours d'édition (pour ne pas se
   bloquer soi-même : le serveur restaure puis revalide dans la transaction). */
function ownReservedQty(productId) {
  if (!invoiceState.editingId) return 0;
  return (invoiceState.editingItems || [])
    .filter(i => String(i.product_id || '') === String(productId))
    .reduce((s, i) => s + (Number(i.quantity) || 0), 0);
}

/* Valide côté client que les quantités ne dépassent pas le stock disponible
   (en tenant compte de la réservation de la facture en cours d'édition).
   Retourne un message d'erreur ou null si tout est OK. */
function validateItemsStock(items) {
  for (const item of items) {
    if (!item.product_id) continue;
    const product = invoiceState.products.find(p => String(p.id) === String(item.product_id));
    if (!product || product.stock_quantity == null) continue;
    const available = Number(product.stock_quantity) + ownReservedQty(item.product_id);
    if (item.quantity > available + 0.0001) {
      return `Stock insuffisant pour « ${product.name} » : ${item.quantity} demandé(s), ${available} disponible(s).`;
    }
  }
  return null;
}

function recalcTotals() {
  const items = collectItems();
  let ht = 0;
  let vat = 0;
  items.forEach(item => {
    const lineTotal = Math.round(item.quantity * item.unit_price * 100) / 100;
    ht += lineTotal;
    vat += Math.round(lineTotal * item.vat_rate) / 100;
  });
  ht = Math.round(ht * 100) / 100;
  vat = Math.round(vat * 100) / 100;
  const discountInput = document.getElementById('invoiceDiscount');
  const discount = Math.min(parseFloat(discountInput ? discountInput.value : 0) || 0, ht + vat);
  const ttc = Math.round((ht + vat - discount) * 100) / 100;
  document.getElementById('invoiceTotalHt').textContent = formatCurrency(ht);
  document.getElementById('invoiceTotalVat').textContent = formatCurrency(vat);
  document.getElementById('invoiceTotalTtc').textContent = formatCurrency(ttc);

  document.querySelectorAll('#invoiceItemsRows .invoice-item-row__total').forEach((el, i) => {
    const item = items[i];
    if (item) {
      const lineTotal = Math.round(item.quantity * item.unit_price * 100) / 100;
      el.textContent = formatCurrency(lineTotal);
    } else {
      el.textContent = '0,00 €';
    }
  });
}

/* ============ MODAL CRÉATION / ÉDITION ============ */
function openNewInvoice(event) {
  if (event) event.preventDefault();
  invoiceState.editingId = null;

  document.getElementById('invoiceModalTitle').textContent = 'Nouvelle facture';
  document.getElementById('invoiceId').value = '';
  document.getElementById('invoiceClient').value = '';
  const due = new Date();
  due.setDate(due.getDate() + 30);
  document.getElementById('invoiceDueDate').value = due.toISOString().slice(0, 10);
  document.getElementById('invoiceStatus').value = 'brouillon';
  document.getElementById('invoicePaymentMethod').value = 'carte';
  document.getElementById('invoiceDiscount').value = '0';
  document.getElementById('invoiceNotes').value = '';
  updatePaymentMethodVisibility();

  document.getElementById('invoiceItemsRows').innerHTML = '';
  addItemRow();
  addItemRow();

  openModal('invoiceModal');
}

function openEditInvoice(invoiceId) {
  api('get&id=' + invoiceId)
    .then(data => {
      const inv = data.invoice;
      invoiceState.editingId = inv.id;
      invoiceState.editingItems = (inv.items || []).map(i => ({ product_id: i.product_id, quantity: Number(i.quantity) || 0 }));

      document.getElementById('invoiceModalTitle').textContent = 'Modifier ' + inv.ref;
      document.getElementById('invoiceId').value = inv.id;
      document.getElementById('invoiceClient').value = inv.client_id || '';
      document.getElementById('invoiceDueDate').value = (inv.due_date || '').slice(0, 10);
      document.getElementById('invoiceStatus').value = inv.status || 'brouillon';
      document.getElementById('invoicePaymentMethod').value = 'carte';
      document.getElementById('invoiceDiscount').value = inv.discount || 0;
      document.getElementById('invoiceNotes').value = inv.notes || '';
      recalcTotals();
      updatePaymentMethodVisibility();

      document.getElementById('invoiceItemsRows').innerHTML = '';
      (inv.items || []).forEach(item => addItemRow({
        product_id: item.product_id,
        description: item.description,
        quantity: item.quantity,
        unit_price: item.unit_price,
        vat_rate: item.vat_rate,
      }));
      if (!(inv.items || []).length) addItemRow();

      openModal('invoiceModal');
    })
    .catch(error => toast(error.message, 'error'));
}

function updatePaymentMethodVisibility() {
  const wrap = document.getElementById('invoicePaymentMethodWrap');
  const status = document.getElementById('invoiceStatus').value;
  wrap.style.display = status === 'payee' ? '' : 'none';
}

async function submitInvoice(event) {
  event.preventDefault();
  const saveBtn = document.getElementById('btnSaveInvoice');
  saveBtn.disabled = true;

  const items = collectItems();
  if (!items.length) {
    toast('Ajoutez au moins une ligne de facture.', 'error');
    saveBtn.disabled = false;
    return;
  }
  if (!document.getElementById('invoiceDueDate').value) {
    toast('La date d\'échéance est requise.', 'error');
    saveBtn.disabled = false;
    return;
  }

  // Blocage client si une quantité dépasse le stock disponible
  const stockError = validateItemsStock(items);
  if (stockError) {
    toast(stockError, 'error');
    saveBtn.disabled = false;
    return;
  }

  const payload = {
    id: invoiceState.editingId,
    client_id: document.getElementById('invoiceClient').value || 0,
    due_date: document.getElementById('invoiceDueDate').value,
    status: document.getElementById('invoiceStatus').value,
    payment_method: document.getElementById('invoicePaymentMethod').value,
    discount: parseFloat(document.getElementById('invoiceDiscount').value) || 0,
    notes: document.getElementById('invoiceNotes').value.trim(),
    items,
  };

  try {
    const isEdit = Boolean(invoiceState.editingId);
    const data = await api(isEdit ? 'update' : 'create', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
    toast(data.message || (isEdit ? 'Facture mise à jour' : 'Facture créée'), 'success');
    closeModal('invoiceModal');
    await refresh();
  } catch (error) {
    toast(error.message, 'error');
  } finally {
    saveBtn.disabled = false;
  }
}

/* ============ MODAL DÉTAIL ============ */
async function loadIssuer() {
  try {
    const res = await fetch('backend/profile.php?action=get', { credentials: 'same-origin' });
    if (!res.ok) return;
    const data = await res.json();
    invoiceState.issuer = data.user || null;
  } catch (e) { /* silencieux */ }
}

async function openDetail(invoiceId) {
  try {
    if (!invoiceState.issuer) await loadIssuer();
    const data = await api('get&id=' + invoiceId);
    invoiceState.detail = data.invoice;
    renderDetail();
    openModal('invoiceDetailModal');
  } catch (error) {
    toast(error.message, 'error');
  }
}

/* Document facture au format « modèle » (thème violet) — partagé entre
   l'aperçu (modale) et l'impression. */
function invoiceDocHTML(inv) {
  const u = invoiceState.issuer || {};
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
    inv.client_name ? `<p><strong>${esc(inv.client_name)}</strong></p>` : '',
    inv.client_company ? `<p>${esc(inv.client_company)}</p>` : '',
    inv.client_email ? `<p>${esc(inv.client_email)}</p>` : '',
    inv.client_phone ? `<p>${esc(inv.client_phone)}</p>` : '',
    (inv.client_address || inv.client_city)
      ? `<p>${esc(inv.client_address || '')}${inv.client_address && inv.client_city ? ', ' : ''}${esc(inv.client_city || '')}</p>`
      : '',
  ].join('') || '<p>—</p>';

  const rows = (inv.items || []).map(item => `
    <tr>
      <td>${esc(item.description || '—')}</td>
      <td>${formatCurrency(item.unit_price)}</td>
      <td>${item.quantity}</td>
      <td>${item.vat_rate} %</td>
      <td>${formatCurrency(item.line_total)}</td>
    </tr>`).join('')
    || '<tr><td colspan="5" style="text-align:center;color:var(--gray-500);padding:14px;">Aucune ligne</td></tr>';

  const method = String(inv.payment_method || '').replace(/_/g, ' ') || 'à convenir';
  const terms = inv.notes || 'Merci de régler la facture à l\'échéance indiquée.';

  const sum = {
    amount: Number(inv.amount) || 0,
    vat: Number(inv.vat) || 0,
    discount: Number(inv.discount) || 0,
    total: Number(inv.total) || 0,
  };

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
          <div class="inv-doc__doclabel">FACTURE</div>
          <div class="inv-doc__meta">
            <div>N° <strong>${esc(inv.ref || '—')}</strong></div>
            <div>${formatDate(inv.issued_at || inv.created_at)}</div>
          </div>
        </div>
      </div>

      <div class="inv-doc__addresses">
        <div>
          <div class="inv-doc__block-title">Adresse / Émetteur</div>
          ${office}
        </div>
        <div>
          <div class="inv-doc__block-title">Facturé à</div>
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
          <p>${esc(inv.notes || 'Merci de votre confiance.')}</p>
        </div>
        <div class="inv-doc__totals">
          <div><span>Sous-total HT</span><span>${formatCurrency(sum.amount)}</span></div>
          <div><span>TVA</span><span>${formatCurrency(sum.vat)}</span></div>
          ${sum.discount > 0 ? `<div><span>Remise</span><span>− ${formatCurrency(sum.discount)}</span></div>` : ''}
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
          <b>Paiement</b>
          <p>${esc(method)}</p>
          <p>Échéance : ${formatDate(inv.due_date)}</p>
        </div>
        <div>
          <b>Conditions</b>
          <p>${esc(terms)}</p>
        </div>
      </div>
    </div>`;
}

function renderDetail() {
  const inv = invoiceState.detail;
  if (!inv) return;

  document.getElementById('invoiceDetailTitle').textContent = inv.ref || 'Détail de la facture';

  const paymentsRows = (inv.payments || []).map(p => `
    <tr>
      <td>${esc(p.transaction_ref || '—')}</td>
      <td>${formatDate(p.paid_at)}</td>
      <td>${esc(p.payment_method || '—')}</td>
      <td style="font-weight:600;">${formatCurrency(p.amount)}</td>
    </tr>
  `).join('') || '<tr><td colspan="4" style="text-align:center;color:var(--gray-500);">Aucun paiement enregistré</td></tr>';

  document.getElementById('invoiceDetailBody').innerHTML = `
    <div class="d-flex justify-content-between align-items-center mb-3">
      <div><span class="badge-soft ${statusBadge(inv.status)}">${statusLabel(inv.status)}</span></div>
      <div style="font-size:.82rem;color:var(--gray-500);">
        Émise le ${formatDate(inv.issued_at || inv.created_at)} · Échéance ${formatDate(inv.due_date)}
      </div>
    </div>
    ${invoiceDocHTML(inv)}
    <div class="inv-doc__block-title" style="margin-top:18px;">Paiements reçus</div>
    <div class="table-responsive">
      <table class="custom-table">
        <thead><tr><th>Référence</th><th>Date</th><th>Moyen</th><th>Montant</th></tr></thead>
        <tbody>${paymentsRows}</tbody>
      </table>
    </div>
  `;
}

/* ============ TÉLÉCHARGEMENT PDF ============ */
async function downloadInvoice(invoiceId) {
  try {
    const res = await fetch('backend/pdf.php?action=invoice&id=' + encodeURIComponent(invoiceId), { credentials: 'same-origin' });
    if (res.status === 401) {
      window.location.href = 'connexion.html';
      return;
    }
    if (!res.ok) {
      let msg = 'Erreur lors de la génération du PDF (' + res.status + ')';
      try {
        const data = await res.json();
        if (data && data.error) msg = data.error;
      } catch (e) { /* réponse non JSON */ }
      toast(msg, 'error');
      return;
    }
    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    const invoice = invoiceState.invoices.find(i => String(i.id) === String(invoiceId));
    a.href = url;
    a.download = (invoice && invoice.ref ? invoice.ref : 'facture-' + invoiceId) + '.pdf';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    setTimeout(() => URL.revokeObjectURL(url), 1000);
    toast('Facture téléchargée', 'success');
  } catch (error) {
    toast(error.message, 'error');
  }
}

/* ============ IMPRESSION ============ */
async function printInvoice(invoiceId) {
  try {
    if (!invoiceState.issuer) await loadIssuer();
    const data = invoiceState.detail && invoiceState.detail.id === invoiceId
      ? { invoice: invoiceState.detail }
      : await api('get&id=' + invoiceId);
    const inv = data.invoice;
    const printArea = document.getElementById('printArea');
    printArea.innerHTML = invoiceDocHTML(inv);
    setTimeout(() => window.print(), 60);
  } catch (error) {
    toast(error.message, 'error');
  }
}

/* ============ ACTIONS ============ */
async function changeStatus(invoiceId, newStatus) {
  if (newStatus === 'payee') {
    const method = window.prompt('Moyen de paiement reçu ? (carte, virement, especes, mobile_money, cheque)', 'carte');
    if (!method) return;
    await api('status', { method: 'POST', body: JSON.stringify({ id: invoiceId, status: 'payee', payment_method: method }) });
  } else {
    await api('status', { method: 'POST', body: JSON.stringify({ id: invoiceId, status: newStatus }) });
  }
  toast('Statut de la facture mis à jour', 'success');
  await refresh();
}

async function deleteInvoice(invoiceId) {
  const invoice = invoiceState.invoices.find(i => String(i.id) === String(invoiceId));
  const ok = await uiConfirm({
    title: 'Supprimer la facture ?',
    text: 'La facture ' + (invoice ? invoice.ref : '') + ' et ses lignes seront définitivement supprimées. Cette action est irréversible.',
    confirmText: 'Supprimer',
    icon: 'bi-trash',
    danger: true,
  });
  if (!ok) return;

  try {
    const data = await api('delete', { method: 'POST', body: JSON.stringify({ id: invoiceId }) });
    toast(data.message || 'Facture supprimée', 'success');
    if (document.getElementById('invoiceDetailModal') && invoiceState.detail && String(invoiceState.detail.id) === String(invoiceId)) {
      closeModal('invoiceDetailModal');
    }
    await refresh();
  } catch (error) {
    toast(error.message, 'error');
  }
}

/* ============ BOOTSTRAP HELPERS ============ */
function getModal(id) {
  const el = document.getElementById(id);
  return el ? bootstrap.Modal.getOrCreateInstance(el) : null;
}

/* ============ EVENTS ============ */
function attachEvents() {
  document.getElementById('btnNewInvoice').addEventListener('click', openNewInvoice);

  document.getElementById('invoiceForm').addEventListener('submit', submitInvoice);

  document.getElementById('invoiceStatus').addEventListener('change', updatePaymentMethodVisibility);

  const discountInput = document.getElementById('invoiceDiscount');
  if (discountInput) {
    discountInput.addEventListener('input', recalcTotals);
  }

  document.getElementById('btnAddItem').addEventListener('click', () => addItemRow());

  document.getElementById('invoiceItemsRows').addEventListener('input', (event) => {
    const row = event.target.closest('.invoice-item-row');
    if (!row) return;
    if (event.target.classList.contains('invoice-item-row__qty') ||
        event.target.classList.contains('invoice-item-row__price')) {
      const desc = row.querySelector('.invoice-item-row__desc');
      if (!desc.value) {
        desc.value = 'Prestation / article';
      }
    }
    if (event.target.classList.contains('invoice-item-row__qty')) {
      updateRowStockInfo(row);
    }
    recalcTotals();
  });

  document.getElementById('invoiceItemsRows').addEventListener('change', (event) => {
    if (event.target.classList.contains('invoice-item-row__product')) {
      const row = event.target.closest('.invoice-item-row');
      const productId = event.target.value;
      const product = invoiceState.products.find(p => String(p.id) === productId);
      if (product && row) {
        row.querySelector('.invoice-item-row__desc').value = product.name || '';
        row.querySelector('.invoice-item-row__price').value = product.unit_price || 0;
        row.querySelector('.invoice-item-row__vat').value = product.vat_rate || 0;
        updateRowStockInfo(row);
        recalcTotals();
      }
    }
  });

  document.getElementById('invoiceItemsRows').addEventListener('click', (event) => {
    const btn = event.target.closest('.invoice-item-row__remove');
    if (!btn) return;
    const rows = document.querySelectorAll('#invoiceItemsRows .invoice-item-row');
    if (rows.length <= 1) {
      toast('Une facture doit avoir au moins une ligne.', 'info');
      return;
    }
    btn.closest('.invoice-item-row').remove();
    recalcTotals();
  });

  document.getElementById('invoicesTableBody').addEventListener('click', (event) => {
    const viewBtn = event.target.closest('[data-view]');
    const downloadBtn = event.target.closest('[data-download]');
    const editBtn = event.target.closest('[data-edit]');
    const printBtn = event.target.closest('[data-print]');
    const delBtn = event.target.closest('[data-del]');

    if (viewBtn) openDetail(viewBtn.getAttribute('data-view'));
    else if (downloadBtn) downloadInvoice(downloadBtn.getAttribute('data-download'));
    else if (editBtn) openEditInvoice(editBtn.getAttribute('data-edit'));
    else if (printBtn) printInvoice(printBtn.getAttribute('data-print'));
    else if (delBtn) deleteInvoice(delBtn.getAttribute('data-del'));
  });

  document.getElementById('invoicesTableBody').addEventListener('change', (event) => {
    const select = event.target.closest('.invoice-status-select');
    if (!select) return;
    const id = select.getAttribute('data-id');
    const status = select.value;
    if (status === (invoiceState.invoices.find(i => String(i.id) === id) || {}).status) return;
    changeStatus(id, status).catch(error => {
      toast(error.message, 'error');
      refresh();
    });
  });

  document.getElementById('invoicePagination').addEventListener('click', (event) => {
    const btn = event.target.closest('[data-page]');
    if (!btn || btn.classList.contains('disabled')) return;
    invoiceState.filters.page = Number(btn.getAttribute('data-page'));
    refresh().catch(error => toast(error.message, 'error'));
  });

  const statusFilter = document.getElementById('invoiceFilterStatus');
  const clientFilter = document.getElementById('invoiceFilterClient');
  const searchInput = document.getElementById('invoiceFilterSearch');
  const topSearch = document.getElementById('invoiceSearchInput');

  if (statusFilter) {
    statusFilter.addEventListener('change', () => {
      invoiceState.filters.status = statusFilter.value;
      invoiceState.filters.page = 1;
      refresh().catch(error => toast(error.message, 'error'));
    });
  }
  if (clientFilter) {
    clientFilter.addEventListener('change', () => {
      invoiceState.filters.client_id = clientFilter.value;
      invoiceState.filters.page = 1;
      refresh().catch(error => toast(error.message, 'error'));
    });
  }

  function onSearch() {
    invoiceState.filters.q = (searchInput ? searchInput.value : '') || (topSearch ? topSearch.value : '');
    if (searchInput) searchInput.value = invoiceState.filters.q;
    if (topSearch) topSearch.value = invoiceState.filters.q;
    invoiceState.filters.page = 1;
    refresh().catch(error => toast(error.message, 'error'));
  }

  if (searchInput) {
    let timer;
    searchInput.addEventListener('input', () => {
      clearTimeout(timer);
      timer = setTimeout(onSearch, 350);
    });
  }
  if (topSearch) {
    let timer2;
    topSearch.addEventListener('input', () => {
      clearTimeout(timer2);
      timer2 = setTimeout(onSearch, 350);
    });
  }

  document.getElementById('btnRefreshInvoices').addEventListener('click', () => {
    refresh().catch(error => toast(error.message, 'error'));
  });

  document.getElementById('btnDownloadInvoice').addEventListener('click', () => {
    if (invoiceState.detail) downloadInvoice(invoiceState.detail.id);
  });

  document.getElementById('btnPrintInvoice').addEventListener('click', () => {
    if (invoiceState.detail) printInvoice(invoiceState.detail.id);
  });

  document.getElementById('btnEditInvoice').addEventListener('click', () => {
    if (!invoiceState.detail) return;      closeModal('invoiceDetailModal');
      openEditInvoice(invoiceState.detail.id);
  });

  document.getElementById('btnDeleteInvoice').addEventListener('click', () => {
    if (!invoiceState.detail) return;
    deleteInvoice(invoiceState.detail.id);
  });
}

function populateClientFilter() {
  const select = document.getElementById('invoiceFilterClient');
  if (!select) return;
  const current = select.value;
  select.innerHTML = '<option value="">Tous les clients</option>' +
    invoiceState.clients.map(c =>
      `<option value="${c.id}" ${String(c.id) === current ? 'selected' : ''}>${esc(c.company || c.name)}</option>`
    ).join('');
  select.value = current;
}

async function init() {
  try {
    await Promise.all([loadSelectors(), loadList(), loadIssuer()]);
  } catch (error) {
    toast(error.message, 'error');
    return;
  }

  const clientSelect = document.getElementById('invoiceClient');
  if (clientSelect) {
    clientSelect.innerHTML = '<option value="">— Client ponctuel / sans client —</option>' +
      invoiceState.clients.map(c =>
        `<option value="${c.id}">${esc(c.company || c.name)}${c.email ? ' — ' + esc(c.email) : ''}</option>`
      ).join('');
  }
  populateClientFilter();

  attachEvents();
  renderStats();
  renderTable();
  renderPagination();
  renderQuota();
}

document.addEventListener('DOMContentLoaded', init);
