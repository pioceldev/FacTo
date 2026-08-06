// Harnais de validation : simule un navigateur (stubs DOM/fetch/bootstrap)
// pour vérifier que ui.js + auth.js + chaque module se chargent sans erreur,
// que fetchCsrfToken est la source unique, et que le mécanisme de nouvelle
// tentative sur « Token CSRF invalide » (403) fonctionne.
'use strict';

function makeElement() {
  return {
    className: '', style: {}, textContent: '', innerHTML: '', value: '',
    dataset: {}, classList: { add() {}, remove() {}, contains() { return false; }, toggle() {} },
    setAttribute() {}, removeAttribute() {}, appendChild() {}, remove() {},
    addEventListener() {}, querySelector() { return makeElement(); },
    querySelectorAll() { return []; }, closest() { return null; },
  };
}

global.window = global;
global.document = {
  addEventListener() {},
  querySelector() { return makeElement(); },
  querySelectorAll() { return []; },
  getElementById() { return makeElement(); },
  createElement() { return makeElement(); },
  body: makeElement(),
};
global.bootstrap = { Modal: { getOrCreateInstance: () => ({ show() {}, hide() {} }) } };

// -- Fetch simulé : token CSRF + 403 CSRF sur le 1er POST, succès au 2e -----
let postCount = 0;
const postedBodies = [];
global.fetch = async (url, opts) => {
  if (String(url).includes('csrf.php?action=token')) {
    return { ok: true, json: async () => ({ csrf_token: 'TOKEN-123' }) };
  }
  if ((opts && opts.method === 'POST')) {
    postCount++;
    postedBodies.push(String(opts.body || ''));
    if (postCount === 1) {
      return { ok: false, status: 403, json: async () => ({ error: 'Token CSRF invalide' }) };
    }
    return { ok: true, status: 200, json: async () => ({ success: true }) };
  }
  return { ok: true, status: 200, json: async () => ({}) };
};

const fs = require('fs');
const path = require('path');
const base = path.join(process.cwd(), 'scripts');
const shared = ['ui.js', 'auth.js'];

const modules = ['clients', 'factures', 'devis', 'paiements', 'depenses', 'stock', 'produits', 'fournisseurs', 'notifications', 'rapports', 'mon-abonnement', 'choix-abonnement'];

(async () => {
  let fail = 0;
  for (const m of modules) {
    const file = path.join(base, m + '.js');
    if (!fs.existsSync(file)) continue;
    const code = shared.map(f => fs.readFileSync(path.join(base, f), 'utf8')).join('\n;\n') + '\n;\n' + fs.readFileSync(file, 'utf8');
    try {
      (0, eval)(code); // eval indirect : portée globale, comme des <script> classiques
    } catch (e) {
      console.log('ERREUR chargement', m, '->', e.message);
      fail = 1;
      continue;
    }
    if (typeof window.fetchCsrfToken !== 'function') {
      console.log('ERREUR', m, '-> fetchCsrfToken introuvable sur window');
      fail = 1;
      continue;
    }
    const t = await window.fetchCsrfToken();
    if (t !== 'TOKEN-123') {
      console.log('ERREUR', m, '-> token inattendu:', t);
      fail = 1;
      continue;
    }
    console.log(m + ': chargement OK, fetchCsrfToken -> ' + t);
  }

  // -- Test du scénario du bug : 403 « Token CSRF invalide » puis retry -----
  postCount = 0;
  postedBodies.length = 0;
  console.log('\n== Test retry sur 403 CSRF (module clients) ==');
  const file = path.join(base, 'clients.js');
  const code = shared.map(f => fs.readFileSync(path.join(base, f), 'utf8')).join('\n;\n') + '\n;\n' + fs.readFileSync(file, 'utf8');
  (0, eval)(code);
  try {
    const res = await window.api('create', { method: 'POST', body: JSON.stringify({ name: 'Test' }) });
    if (res && res.success) {
      console.log('OK: nouvelle tentative automatique -> succès (' + postCount + ' POST envoyés, ' + postedBodies.length + ' avec _csrf présent)');
      const allHaveCsrf = postedBodies.every(b => b.includes('_csrf') && b.includes('TOKEN-123'));
      if (allHaveCsrf && postCount === 2) {
        console.log('OK: chaque requête contenait bien le token (2 requêtes, retry propre)');
      } else {
        console.log('ATTENTION: requêtes sans token ou nombre inattendu', postCount, postedBodies);
        fail = 1;
      }
    } else {
      console.log('ERREUR: api() a échoué même après retry');
      fail = 1;
    }
  } catch (e) {
    console.log('ERREUR retry:', e.message);
    fail = 1;
  }

  console.log(fail === 0 ? '\nHARNESS: TOUT OK' : '\nHARNESS: ECHEC');
  process.exit(fail);
})();
