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
    // Le compte démarre toujours sur le plan gratuit (sécurité) : le plan
    // sélectionné ici pourra être activé après paiement depuis la page abonnement.
    banner.textContent = `Vous avez sélectionné : ${getPlanLabel(plan)}. Votre compte démarre avec le plan gratuit — vous pourrez activer ce plan après votre inscription.`;
    banner.style.display = 'block';
  } else {
    banner.style.display = 'none';
  }
}

document.addEventListener('DOMContentLoaded', initInscriptionPlan);
