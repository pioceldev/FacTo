const choiceState = {
  plans: [],
  subscription: null,
  cycle: 'monthly',
  paymentMethod: 'carte',
};

async function fetchSubscriptionData() {
  const response = await fetch('backend/subscription.php?action=get', { credentials: 'same-origin' });
  if (!response.ok) {
    throw new Error('Impossible de charger les offres');
  }
  return response.json();
}

function formatPlanLabel(planName) {
  if (!planName) return 'Aucun abonnement actif';
  return `Vous êtes actuellement sur le plan ${planName}`;
}

function planPrice(plan) {
  const price = choiceState.cycle === 'yearly' ? plan.price_yearly : plan.price_monthly;
  const per = choiceState.cycle === 'yearly' ? 'an' : 'mois';
  return { price, per };
}

function planFeatures(plan) {
  try {
    const items = (plan.features || '').split(';').map(s => s.trim()).filter(Boolean);
    if (items.length) return items;
  } catch (e) {
    // ignore
  }
  return [];
}

function getPlanColor(name) {
  const n = name.toLowerCase();
  if (n === 'premium') return { bg: 'var(--primary-light)', color: 'var(--primary)', icon: 'bi-crown' };
  if (n === 'standard') return { bg: 'var(--gray-100)', color: 'var(--gray-600)', icon: 'bi-shield-check' };
  return { bg: 'var(--gray-100)', color: 'var(--gray-600)', icon: 'bi-lightning-charge' };
}

function updateSubscriptionBanner(subscription) {
  const banner = document.getElementById('currentSubscriptionBanner');
  if (!banner) return;

  if (!subscription || !subscription.plan_name) {
    banner.style.display = 'none';
    return;
  }

  const planLabel = formatPlanLabel(subscription.plan_name);
  const status = subscription.status ? subscription.status.charAt(0).toUpperCase() + subscription.status.slice(1).toLowerCase() : 'Inactif';
  const nextBilling = subscription.next_billing_date || 'Non défini';

  banner.innerHTML = `
    <strong>${planLabel}</strong> • Statut : ${status} • Prochaine facturation : ${nextBilling}
    ${subscription.status?.toLowerCase().includes('cancel') ? ' • <a href="mon-abonnement.html" style="color:var(--primary);font-weight:600;">Réactiver</a>' : ''}
  `;
  banner.style.display = 'block';
}

function renderPlans() {
  const grid = document.getElementById('plansGrid');
  if (!grid) return;

  if (!choiceState.plans.length) {
    grid.innerHTML = '<div style="grid-column:1 / -1;text-align:center;color:var(--gray-500);padding:24px;">Aucune offre disponible.</div>';
    return;
  }

  const currentName = choiceState.subscription?.plan_name || '';
  const isCancelled = choiceState.subscription?.status?.toLowerCase().includes('cancel');

  grid.innerHTML = choiceState.plans.map((plan, index) => {
    const { price, per } = planPrice(plan);
    const colors = getPlanColor(plan.name);
    const isCurrent = plan.name.toLowerCase() === currentName.toLowerCase() && !isCancelled;
    const isPremium = plan.name.toLowerCase() === 'premium';
    const features = planFeatures(plan);

    let buttonHtml = '';
    if (isCurrent) {
      buttonHtml = `<button type="button" class="btn btn-outline-secondary w-100" style="border-radius:8px;padding:10px;" disabled>Plan actuel</button>`;
    } else {
      buttonHtml = `<button type="button" class="btn w-100 plan-action-button" data-plan-id="${plan.id}" style="border-radius:8px;padding:10px;">Passer à ${plan.name} <i class="bi bi-arrow-right"></i></button>`;
    }

    return `
      <div class="plan-card h-100 ${isPremium ? 'plan-card--popular' : ''} animate-in" style="animation-delay:${index * 80}ms">
        ${isPremium ? '<div class="plan-card__badge">LE PLUS POPULAIRE</div>' : ''}
        <div class="plan-card__icon" style="background:${colors.bg};color:${colors.color};"><i class="bi ${colors.icon}"></i></div>
        <div class="plan-card__name">${plan.name}</div>
        <div class="plan-card__desc">${plan.description || ''}</div>
        <div class="plan-card__price">${Number(price).toLocaleString('fr-FR')}€<span>/${per}</span></div>
        <ul class="plan-card__features">
          ${features.map(f => `<li class="included"><i class="bi bi-check-circle-fill text-primary"></i> ${f}</li>`).join('') || '<li class="not-included"><i class="bi bi-circle"></i> Fonctionnalités à venir</li>'}
        </ul>
        ${buttonHtml}
      </div>
    `;
  }).join('');
}

function attachPlanButtons() {
  document.querySelectorAll('.plan-action-button').forEach((button) => {
    button.addEventListener('click', async (event) => {
      event.preventDefault();
      const planId = button.getAttribute('data-plan-id');
      const plan = choiceState.plans.find(p => String(p.id) === planId);
      if (!plan) return;

      const cycleLabel = choiceState.cycle === 'yearly' ? 'annuel' : 'mensuel';
      const price = choiceState.cycle === 'yearly' ? plan.price_yearly : plan.price_monthly;
      const isPaid = Number(price) > 0;

      const ok = await uiConfirm({
        title: `Passer au plan ${plan.name}`,
        text: isPaid
          ? `Cycle ${cycleLabel} — ${Number(price).toLocaleString('fr-FR')} €. Vous serez redirigé vers la page de paiement sécurisée FedaPay.`
          : 'Ce plan est gratuit. Votre abonnement sera activé immédiatement.',
        confirmText: isPaid ? 'Payer avec FedaPay' : 'Activer',
        icon: isPaid ? 'bi-credit-card' : 'bi-lightning-charge',
      });
      if (!ok) return;

      button.disabled = true;
      button.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> Traitement…';
      try {
        const _csrf = await fetchCsrfToken(); const response = await fetch('backend/subscription.php?action=subscribe', {
          method: 'POST',
          body: new URLSearchParams({
            plan_id: plan.id,
            cycle: choiceState.cycle,
            payment_method: choiceState.paymentMethod,
          }),
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await response.json();

        if (data.error) {
          toast(data.error, 'error');
          return;
        }

        if (data.mode === 'fedapay' && data.payment_url) {
          toast(data.message || 'Redirection vers FedaPay…', 'info', 2500);
          setTimeout(() => { window.location.href = data.payment_url; }, 800);
          return;
        }

        toast(data.message || 'Abonnement activé', 'success');
        await initChoiceSubscriptionPage();
      } catch (e) {
        toast('Impossible de contacter le serveur.', 'error');
      } finally {
        button.disabled = false;
        button.innerHTML = `Passer à ${plan.name} <i class="bi bi-arrow-right"></i>`;
      }
    });
  });
}

function attachCycleToggle() {
  const toggleBtns = document.querySelectorAll('.toggle-switch .toggle-btn');
  if (!toggleBtns.length) return;

  toggleBtns.forEach((btn) => {
    btn.addEventListener('click', () => {
      const text = btn.textContent.toLowerCase();
      const nextCycle = text.includes('annuel') ? 'yearly' : 'monthly';
      if (nextCycle === choiceState.cycle) return;
      choiceState.cycle = nextCycle;
      toggleBtns.forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      renderPlans();
      attachPlanButtons();
    });
  });
}

async function initChoiceSubscriptionPage() {
  const grid = document.getElementById('plansGrid');
  if (grid) showSkeleton(grid, 3);
  try {
    const data = await fetchSubscriptionData();
    choiceState.subscription = data.subscription || null;
    choiceState.plans = data.plans || [];
    choiceState.paymentMethod = data.subscription?.payment_method || 'carte';
    updateSubscriptionBanner(data.subscription);
    renderPlans();
    attachPlanButtons();
  } catch (error) {
    console.error(error);
    if (grid) grid.innerHTML = '<div style="grid-column:1 / -1;text-align:center;color:var(--gray-500);padding:24px;">Impossible de charger les offres.</div>';
  }
}

document.addEventListener('DOMContentLoaded', () => {
  attachCycleToggle();
  initChoiceSubscriptionPage();
});
