function getQueryParam(param) {
  const url = new URL(window.location.href);
  return url.searchParams.get(param);
}

function getPlanLabel(plan) {
  if (!plan) return 'Aucun plan sélectionné';
  const labels = {
    free: 'Forfait Gratuit',
    standard: 'Plan Standard',
    premium: 'Plan Premium',
  };
  return labels[plan] || `Plan ${plan}`;
}

function initInscriptionPlan() {
  const plan = getQueryParam('plan');
  const planInput = document.getElementById('selectedPlanInput');
  const banner = document.getElementById('selectedPlanBanner');

  if (!planInput || !banner) return;

  if (plan) {
    planInput.value = plan;
    banner.textContent = `Vous avez sélectionné : ${getPlanLabel(plan)}. Vous pourrez modifier ce choix plus tard.`;
    banner.style.display = 'block';
  } else {
    banner.style.display = 'none';
  }
}

document.addEventListener('DOMContentLoaded', initInscriptionPlan);
