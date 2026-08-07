const subscriptionState = {
  subscription: null,
  plans: [],
  history: [],
  usage: { invoice_count: 0, invoice_limit: 0 },
};

function esc(value) {
  return String(value == null ? '' : value)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function formatCurrency(amount) {
  return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'XOF', maximumFractionDigits: 0 }).format(Number(amount) || 0);
}

function formatDate(value) {
  if (!value) return '-';
  try {
    return new Date(value).toLocaleDateString('fr-FR');
  } catch {
    return value;
  }
}

async function fetchSubscription() {
  const response = await fetch('backend/subscription.php?action=get', { credentials: 'same-origin' });
  if (!response.ok) {
    throw new Error('Impossible de charger l’abonnement');
  }
  return response.json();
}

function formatStatusLabel(status) {
  return status?.charAt(0).toUpperCase() + status?.slice(1).toLowerCase();
}

function formatPrice(price, startDate, nextBilling) {
  const amount = price != null ? Number(price) : null;
  if (amount == null) return 'Non disponible';

  let cycle = 'mois';
  try {
    const start = new Date(startDate);
    const next = new Date(nextBilling);
    const months = (next - start) / (1000 * 60 * 60 * 24 * 30.4);
    if (months >= 11) cycle = 'an';
  } catch (e) {
    // ignore
  }
  return `${amount.toLocaleString('fr-FR', { style: 'currency', currency: 'XOF', maximumFractionDigits: 0 })} / ${cycle}`;
}

function statusBadge(status) {
  const normalized = (status || '').toLowerCase();
  if (normalized.includes('active') || normalized.includes('paid')) return 'badge-soft-success';
  if (normalized.includes('cancel')) return 'badge-soft-danger';
  if (normalized.includes('pending')) return 'badge-soft-warning';
  return 'badge-soft-secondary';
}

function updateSubscriptionUI(subscription) {
  const planName = document.getElementById('subscriptionPlanName');
  const planStatus = document.getElementById('subscriptionPlanStatus');
  const planDesc = document.getElementById('subscriptionPlanDesc');
  const renewalDate = document.getElementById('subscriptionRenewalDate');
  const amount = document.getElementById('subscriptionAmount');
  const paymentMode = document.getElementById('subscriptionPaymentMode');
  const usageCount = document.getElementById('subscriptionUsageCount');
  const usageBar = document.getElementById('subscriptionUsageBar');

  const isCancelled = subscription?.status?.toLowerCase().includes('cancel');

  if (planName) planName.textContent = subscription.plan_name || 'Plan inconnu';
  if (planStatus) {
    planStatus.textContent = formatStatusLabel(subscription.status || 'inactif');
    planStatus.className = 'plan-current__badge ' + statusBadge(subscription.status);
  }
  if (planDesc) {
    planDesc.textContent = subscription.plan_name === 'Business'
      ? 'L\'abonnement complet FacTo : factures, devis et stock illimités.'
      : 'Un plan conçu pour accompagner votre croissance.';
  }
  if (renewalDate) renewalDate.textContent = subscription.next_billing_date || '-';
  if (amount) amount.textContent = formatPrice(subscription.price, subscription.started_at, subscription.next_billing_date);
  if (paymentMode) paymentMode.textContent = subscription.payment_method
    ? subscription.payment_method.charAt(0).toUpperCase() + subscription.payment_method.slice(1)
    : 'Carte non renseignée';

  const limit = subscriptionState.usage.invoice_limit || 0;
  const count = subscriptionState.usage.invoice_count || 0;
  if (usageCount) usageCount.textContent = limit >= 999999 ? `${count} / illimité` : `${count} / ${limit}`;
  if (usageBar) {
    const pct = limit >= 999999 ? (count > 0 ? Math.min(count, 100) : 0) : limit > 0 ? Math.min(100, Math.round((count / limit) * 100)) : 0;
    usageBar.style.width = pct + '%';
    usageBar.className = 'progress-bar' + (pct >= 80 ? ' bg-danger' : '');
  }

  if (subscription.next_billing_date) {
    startSubscriptionCountdown(subscription.next_billing_date);
  }

  const paymentTitle = document.getElementById('paymentCardTitle');
  const paymentMeta = document.getElementById('paymentCardMeta');
  if (paymentTitle) {
    paymentTitle.textContent = subscription.payment_method
      ? subscription.payment_method.charAt(0).toUpperCase() + subscription.payment_method.slice(1)
      : 'Carte bancaire';
  }
  if (paymentMeta) {
    paymentMeta.textContent = isCancelled ? 'Abonnement résilié' : `Paiement ${formatStatusLabel(subscription.status)}`;
  }

  // Code business (parrainage)
  const codeDisplay = document.getElementById('referralCodeDisplay');
  if (codeDisplay && subscriptionState.businessCode) {
    codeDisplay.textContent = subscriptionState.businessCode;
  }
  const btnCopy = document.getElementById('btnCopyReferralCode');
  if (btnCopy) {
    btnCopy.addEventListener('click', () => {
      const code = (subscriptionState.businessCode || codeDisplay?.textContent || '').trim();
      if (!code) return;
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(code).then(() => toast('Code copié !', 'success')).catch(() => toast(code, 'info'));
      } else {
        toast(code, 'info');
      }
    });
  }
  const discountNote = document.getElementById('referralDiscountNote');
  const discountPct = Number(subscription?.discount_next_pct || 0);
  if (discountNote) {
    if (discountPct > 0) {
      discountNote.style.display = 'block';
      discountNote.innerHTML = `<i class="bi bi-gift-fill"></i> 🎁 ${discountPct} % de réduction sur votre prochain renouvellement !`;
    } else {
      discountNote.style.display = 'none';
    }
  }

  const btnCancel = document.getElementById('btnCancel');
  if (btnCancel) {
    if (isCancelled) {
      btnCancel.innerHTML = '<i class="bi bi-arrow-counterclockwise"></i> Réactiver l\'abonnement';
      btnCancel.classList.remove('action-card__btn--danger');
      btnCancel.classList.add('action-card__btn--outline');
    } else {
      btnCancel.innerHTML = '<i class="bi bi-x-circle"></i> Résilier l\'abonnement';
      btnCancel.classList.add('action-card__btn--danger');
      btnCancel.classList.remove('action-card__btn--outline');
    }
  }

  const historyBody = document.getElementById('subscriptionInvoicesBody');
  if (historyBody) {
    historyBody.addEventListener('click', (event) => {
      const btn = event.target.closest('[data-receipt]');
      if (btn) printReceipt(btn.getAttribute('data-receipt'));
    });
  }
}

function renderPaymentHistory() {
  const tbody = document.getElementById('subscriptionInvoicesBody');
  if (!tbody) return;

  if (!subscriptionState.history.length) {
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--gray-500);padding:20px;">Aucun paiement enregistré pour le moment.</td></tr>';
    return;
  }

  tbody.innerHTML = subscriptionState.history.map((p) => `
    <tr>
      <td>${p.transaction_ref || '—'}</td>
      <td>${p.created_at ? new Date(p.created_at).toLocaleDateString('fr-FR') : '—'}</td>
      <td>${p.payment_method || '—'}</td>
      <td>${Number(p.amount).toLocaleString('fr-FR', { style: 'currency', currency: 'XOF', maximumFractionDigits: 0 })}</td>
      <td><span class="badge-soft ${statusBadge(p.status)}">${formatStatusLabel(p.status)}</span></td>
      <td><button type="button" class="btn btn-sm btn-outline-primary" data-receipt="${p.id}" title="Imprimer / télécharger le reçu"><i class="bi bi-receipt"></i> Reçu</button></td>
    </tr>
  `).join('');
}

/* ============ REÇU DE PAIEMENT PDF ============ */
let receiptIssuer = null;

async function loadReceiptIssuer() {
  if (receiptIssuer) return;
  try {
    const res = await fetch('backend/profile.php?action=get', { credentials: 'same-origin' });
    if (!res.ok) return;
    const data = await res.json();
    receiptIssuer = data.user || {};
  } catch (e) {
    receiptIssuer = {};
  }
}

function receiptDocHTML(p, planName) {
  const u = receiptIssuer || {};
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

  const payer = [
    `<p><strong>${esc(u.name || company)}</strong></p>`,
    u.email ? `<p>${esc(u.email)}</p>` : '',
  ].join('');

  const ref = p.transaction_ref || ('RCPT-' + (p.id || ''));
  const date = p.paid_at || p.created_at;
  const description = 'Abonnement ' + (planName || '') + ' — ' + (p.payment_method || 'Paiement');
  const statusLabel2 = String(p.status || '').toLowerCase() === 'paid' ? 'Payé' : formatStatusLabel(p.status);

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
          <div class="inv-doc__doclabel">REÇU</div>
          <div class="inv-doc__meta">
            <div>N° <strong>${esc(ref)}</strong></div>
            <div>${formatDate(date)}</div>
          </div>
        </div>
      </div>

      <div class="inv-doc__addresses">
        <div>
          <div class="inv-doc__block-title">Émetteur</div>
          ${office}
        </div>
        <div>
          <div class="inv-doc__block-title">Payé par</div>
          ${payer}
        </div>
      </div>

      <table class="inv-doc__table">
        <thead>
          <tr><th>Désignation</th><th>Méthode</th><th>Statut</th><th>Montant</th></tr>
        </thead>
        <tbody>
          <tr>
            <td>${esc(description)}</td>
            <td>${esc(p.payment_method || '—')}</td>
            <td>${esc(statusLabel2)}</td>
            <td>${formatCurrency(p.amount)}</td>
          </tr>
        </tbody>
      </table>

      <div class="inv-doc__sum">
        <div class="inv-doc__note">
          <div class="inv-doc__block-title">Note</div>
          <p>Merci pour votre paiement. Ce document fait office de reçu.</p>
        </div>
        <div class="inv-doc__totals">
          <div class="inv-grand"><span>Total payé</span><span>${formatCurrency(p.amount)}</span></div>
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
          <p>${esc(p.payment_method || '—')}</p>
          <p>Réf. : ${esc(ref)}</p>
        </div>
        <div>
          <b>Conditions</b>
          <p>Reçu de paiement — ${esc(company)}</p>
        </div>
      </div>
    </div>`;
}

async function printReceipt(paymentId) {
  try {
    await loadReceiptIssuer();
    const payment = subscriptionState.history.find(h => String(h.id) === String(paymentId));
    if (!payment) { toast('Paiement introuvable', 'error'); return; }
    const planName = subscriptionState.subscription?.plan_name || '';
    const printArea = document.getElementById('printArea');
    printArea.innerHTML = receiptDocHTML(payment, planName);
    setTimeout(() => window.print(), 60);
  } catch (error) {
    toast(error.message, 'error');
  }
}

function getTimeRemaining(targetDate) {
  const now = new Date();
  const endDate = new Date(targetDate);
  const diff = endDate.getTime() - now.getTime();

  if (diff <= 0) {
    return null;
  }

  const days = Math.floor(diff / (1000 * 60 * 60 * 24));
  const hours = Math.floor((diff / (1000 * 60 * 60)) % 24);
  const minutes = Math.floor((diff / (1000 * 60)) % 60);
  const seconds = Math.floor((diff / 1000) % 60);

  return { days, hours, minutes, seconds };
}

function formatCountdown(time) {
  if (!time) return 'Expiré';
  const parts = [];
  if (time.days > 0) parts.push(`${time.days}j`);
  if (time.hours > 0 || time.days > 0) parts.push(`${time.hours}h`);
  parts.push(`${String(time.minutes).padStart(2, '0')}m`);
  parts.push(`${String(time.seconds).padStart(2, '0')}s`);
  return parts.join(' ');
}

let countdownInterval;

function startSubscriptionCountdown(targetDate) {
  const countdownEl = document.getElementById('subscriptionCountdown');
  if (!countdownEl) return;

  function updateCountdown() {
    const remaining = getTimeRemaining(targetDate);
    if (!remaining) {
      countdownEl.textContent = 'Renouvellement imminent';
      clearInterval(countdownInterval);
      return;
    }
    countdownEl.textContent = formatCountdown(remaining);
  }

  updateCountdown();
  clearInterval(countdownInterval);
  countdownInterval = setInterval(updateCountdown, 1000);
}

async function apiCall(action, body) {
  const _csrf = await fetchCsrfToken();
  const payload = new URLSearchParams(body);
  payload.set('_csrf', _csrf || '');
  const response = await fetch('backend/subscription.php?action=' + action, {
    method: 'POST',
    body: payload,
    credentials: 'same-origin',
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  });
  return response.json();
}

let compareCycle = 'monthly';

function planLabel(name) {
  const n = (name || '').toLowerCase();
  if (n === 'free' || n === 'gratuit') return 'Gratuit';
  if (n === 'business') return 'Business';
  return name || '—';
}

function planFeatures(plan) {
  const raw = (plan.features || '').split(';').map(s => s.trim()).filter(Boolean);
  return raw.length ? raw : ['Fonctionnalités à venir'];
}

function renderComparePlans() {
  const grid = document.getElementById('comparePlansGrid');
  if (!grid) return;

  const plans = [...subscriptionState.plans].sort((a, b) => (a.sort_order ?? 0) - (b.sort_order ?? 0));
  if (!plans.length) {
    grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;color:var(--gray-500);padding:24px;">Aucune offre disponible pour le moment.</div>';
    return;
  }

  const currentPlan = (subscriptionState.subscription?.plan_name || '').toLowerCase();
  const isYearly = compareCycle === 'yearly';

  grid.innerHTML = plans.map((plan) => {
    const name = planLabel(plan.name);
    const isCurrent = currentPlan === (plan.name || '').toLowerCase();
    const isPremium = (plan.name || '').toLowerCase() === 'business';
    const monthly = Number(plan.price_monthly) || 0;
    const yearly = Number(plan.price_yearly) || 0;
    const displayPrice = isYearly && yearly > 0 ? yearly / 12 : monthly;
    const priceStr = displayPrice === 0
      ? 'Gratuit'
      : displayPrice.toLocaleString('fr-FR', { style: 'currency', currency: 'XOF', maximumFractionDigits: 0 });

    const icon = isPremium ? 'bi-crown' : isCurrent ? 'bi-star-fill' : 'bi-lightning-charge';
    const iconBg = isPremium
      ? 'background:var(--gray-800);color:#fff;'
      : isCurrent
        ? 'background:var(--primary-light);color:var(--primary);margin-top:20px;'
        : 'background:var(--gray-100);color:var(--gray-600);';

    return `
      <div class="compare-card ${isCurrent ? 'compare-card--current' : ''}">
        ${isCurrent ? '<div class="compare-card__current-badge">PLAN ACTUEL</div>' : ''}
        <div class="compare-card__icon" style="${iconBg}"><i class="bi ${icon}"></i></div>
        <div class="compare-card__name" ${isPremium ? 'style="color:var(--primary);"' : ''}>${name}</div>
        <div class="compare-card__desc">${plan.description || ''}</div>
        <div class="compare-card__price">${priceStr}<span>/mois</span></div>
        ${isYearly && yearly > 0 ? '<div style="font-size:.72rem;color:var(--success);font-weight:600;margin-top:-8px;margin-bottom:12px;">Facturation annuelle (−20%)</div>' : ''}
        <ul class="compare-card__features">
          ${planFeatures(plan).map(f => `<li><i class="bi bi-check-lg"></i> ${f}</li>`).join('')}
        </ul>
        ${isCurrent
          ? '<button class="btn btn-outline-secondary compare-card__btn" style="border-color:var(--gray-200);color:var(--gray-500);" disabled>Votre plan actuel</button>'
          : `<a href="choix-abonnement.html" class="btn btn-primary compare-card__btn">${isPremium ? 'Passer à Business' : 'Choisir ce plan'}</a>`}
      </div>`;
  }).join('');
}

function attachActions() {
  const toggleMonthly = document.getElementById('toggleMonthly');
  const toggleYearly = document.getElementById('toggleYearly');
  if (toggleMonthly && toggleYearly) {
    const setToggle = (cycle, btn) => {
      compareCycle = cycle;
      toggleMonthly.classList.toggle('active', cycle === 'monthly');
      toggleYearly.classList.toggle('active', cycle === 'yearly');
      renderComparePlans();
    };
    toggleMonthly.addEventListener('click', () => setToggle('monthly', toggleMonthly));
    toggleYearly.addEventListener('click', () => setToggle('yearly', toggleYearly));
  }

  const btnChange = document.getElementById('btnChangePlan');
  if (btnChange) {
    btnChange.addEventListener('click', () => {
      window.location.href = 'choix-abonnement.html';
    });
  }

  const btnPayment = document.getElementById('btnUpdatePayment');
  if (btnPayment) {
    btnPayment.addEventListener('click', async () => {
      const method = window.prompt('Méthode de paiement (ex: carte, mtn_open, moov, paypal) :', subscriptionState.subscription?.payment_method || 'carte');
      if (!method) return;
      const data = await apiCall('update_payment', { payment_method: method });
      if (data.error) { toast(data.error, 'error'); return; }
      toast(data.message || 'Méthode de paiement mise à jour', 'success');
      await initSubscriptionPage();
    });
  }

  const btnCancel = document.getElementById('btnCancel');
  if (btnCancel) {
    btnCancel.addEventListener('click', async () => {
      const isCancelled = subscriptionState.subscription?.status?.toLowerCase().includes('cancel');
      if (isCancelled) {
        const ok = await uiConfirm({
          title: 'Réactiver l\'abonnement',
          text: 'Relancer votre abonnement actuel immédiatement ? Un paiement sera requis.',
          confirmText: 'Réactiver',
          icon: 'bi-arrow-counterclockwise',
        });
        if (!ok) return;
        const current = subscriptionState.subscription;
        const plan = subscriptionState.plans.find(p => p.name.toLowerCase() === (current.plan_name || '').toLowerCase());
        if (!plan) { toast('Plan introuvable', 'error'); return; }
        const data = await apiCall('subscribe', { plan_id: plan.id, cycle: 'monthly', payment_method: current.payment_method || 'carte' });
        if (data.error) { toast(data.error, 'error'); return; }
        if (data.mode === 'fedapay' && data.payment_url) {
          toast('Redirection vers FedaPay…', 'info');
          window.location.href = data.payment_url;
          return;
        }
        toast(data.message || 'Abonnement réactivé', 'success');
      } else {
        const ok = await uiConfirm({
          title: 'Résilier l\'abonnement ?',
          text: 'Vous conserverez l\'accès jusqu\'à la fin de la période en cours. Cette action peut être annulée plus tard.',
          confirmText: 'Résilier',
          icon: 'bi-x-circle',
          danger: true,
        });
        if (!ok) return;
        const data = await apiCall('cancel');
        if (data.error) { toast(data.error, 'error'); return; }
        toast(data.message || 'Abonnement résilié', 'success');
      }
      await initSubscriptionPage();
    });
  }
}

async function initSubscriptionPage() {
  try {
    const data = await fetchSubscription();
    subscriptionState.subscription = data.subscription || null;
    subscriptionState.plans = data.plans || [];
    subscriptionState.history = data.payment_history || [];
    subscriptionState.usage = data.usage || { invoice_count: 0, invoice_limit: 0 };
    subscriptionState.businessCode = data.business_code || null;

    if (data.subscription) {
      updateSubscriptionUI(data.subscription);
    }
    renderPaymentHistory();
    renderComparePlans();
  } catch (error) {
    console.error(error);
  }
}

document.addEventListener('DOMContentLoaded', () => {
  attachActions();
  initSubscriptionPage();
});
