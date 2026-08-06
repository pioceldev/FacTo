#!/usr/bin/env bash
# FacTo - smoke test end-to-end des flux de création
set -u
# Detection du binaire PHP : PATH d'abord, fallback WAMP Windows
if command -v php >/dev/null 2>&1; then
  PHP=$(command -v php)
else
  PHP=$(ls /c/wamp64/bin/php/*/php.exe 2>/dev/null | head -1)
fi
[ -n "$PHP" ] || { echo "PHP introuvable — ajoutez-le au PATH."; exit 1; }
BASE=http://127.0.0.1:8123
CJ=/tmp/facto-cookies.txt
PASS=0; FAIL=0

ok()   { PASS=$((PASS+1)); echo "  ✅ $1"; }
ko()   { FAIL=$((FAIL+1)); echo "  ❌ $1  ==>  $2"; }

# --- nettoyage serveur + cookies
rm -f "$CJ"
curl -s -o /dev/null http://127.0.0.1:8123/ 2>/dev/null && { echo "Serveur deja actif, on le laisse."; }
nohup "$PHP" -S 127.0.0.1:8123 >/tmp/facto-server.log 2>&1 &
SRV=$!
sleep 1.5

echo "== Inscription (plan=premium doit etre force a free) =="
EMAIL="e2e-$(date +%s)@facto.test"
REG=$(curl -s -c "$CJ" -b "$CJ" -X POST "$BASE/backend/auth.php?action=register" \
  -d "name=Testeur&email=$EMAIL&password=Test1234&company=TestCo&phone=0600000000&plan=premium")
echo "$REG" | grep -q '"success":true' && ok "inscription" || ko "inscription" "$REG"

echo "== Connexion =="
LOG=$(curl -s -c "$CJ" -b "$CJ" -X POST "$BASE/backend/auth.php?action=login" \
  -d "email=$EMAIL&password=Test1234")
echo "$LOG" | grep -q '"success":true' && ok "connexion" || ko "connexion" "$LOG"

echo "== Token CSRF =="
TOKEN=$(curl -s -c "$CJ" -b "$CJ" "$BASE/backend/csrf.php?action=token" | "$PHP" -r 'echo json_decode(stream_get_contents(STDIN), true)["csrf_token"] ?? "";')
[ -n "$TOKEN" ] && ok "token CSRF recu" || ko "token CSRF" "vide"
[ -n "$TOKEN" ] || exit 1

# Verifie qu'un element cree apparait bien dans la liste (affichage dynamique)
in_list() { # $1=cle liste, $2=id, $3=url
  local list
  list=$(curl -s -c "$CJ" -b "$CJ" "$BASE/$3")
  local found
  found=$(echo "$list" | "$PHP" -r '$d=json_decode(stream_get_contents(STDIN), true); $key=$argv[1]; $id=$argv[2]; foreach (($d[$key] ?? []) as $it) { if ((string)($it["id"] ?? "") === (string)$id) { echo "1"; exit; } }' "$1" "$2")
  if [ "$found" = "1" ]; then ok "$1 : element #$2 affiche dans la liste"; else ko "$1 : element #$2 ABSENT de la liste" "$(echo "$list" | head -c 200)"; fi
}

echo "== Client =="
CLIENT=$(curl -s -c "$CJ" -b "$CJ" -X POST "$BASE/backend/clients.php?action=create" \
  -H "Content-Type: application/json" \
  --data "{\"name\":\"Client Test\",\"company\":\"ACME\",\"email\":\"client@test.fr\",\"phone\":\"0102030405\",\"address\":\"1 rue X\",\"city\":\"Paris\",\"zip\":\"75001\",\"country\":\"France\",\"_csrf\":\"$TOKEN\"}")
CLIENT_ID=$(echo "$CLIENT" | "$PHP" -r 'echo json_decode(stream_get_contents(STDIN), true)["client"]["id"] ?? json_decode(stream_get_contents(STDIN), true)["id"] ?? "";')
echo "$CLIENT" | grep -q '"success":true' && ok "client cree (id=$CLIENT_ID)" || ko "client" "$CLIENT"
in_list "clients" "$CLIENT_ID" "backend/clients.php?action=list"

echo "== Produit =="
PROD=$(curl -s -c "$CJ" -b "$CJ" -X POST "$BASE/backend/products.php?action=create" \
  -H "Content-Type: application/json" \
  --data "{\"name\":\"Prestation de service\",\"sku\":\"SKU-TEST-1\",\"description\":\"Test\",\"unit_price\":150,\"vat_rate\":20,\"is_active\":true,\"_csrf\":\"$TOKEN\"}")
PROD_ID=$(echo "$PROD" | "$PHP" -r '$d=json_decode(stream_get_contents(STDIN), true); echo $d["product"]["id"] ?? $d["id"] ?? "";')
echo "$PROD" | grep -q '"success":true' && ok "produit cree (id=$PROD_ID)" || ko "produit" "$PROD"
in_list "products" "$PROD_ID" "backend/products.php?action=list"

echo "== Facture =="
INV=$(curl -s -c "$CJ" -b "$CJ" -X POST "$BASE/backend/invoices.php?action=create" \
  -H "Content-Type: application/json" \
  --data "{\"client_id\":$CLIENT_ID,\"due_date\":\"2026-09-05\",\"status\":\"brouillon\",\"payment_method\":\"carte\",\"notes\":\"Test E2E\",\"items\":[{\"product_id\":$PROD_ID,\"description\":\"Prestation de service\",\"quantity\":2,\"unit_price\":150,\"vat_rate\":20}],\"_csrf\":\"$TOKEN\"}")
INV_ID=$(echo "$INV" | "$PHP" -r '$d=json_decode(stream_get_contents(STDIN), true); echo $d["invoice"]["id"] ?? $d["id"] ?? "";')
INV_REF=$(echo "$INV" | "$PHP" -r '$d=json_decode(stream_get_contents(STDIN), true); echo $d["invoice"]["ref"] ?? $d["ref"] ?? "";')
echo "$INV" | grep -q '"success":true' && ok "facture cree (ref=$INV_REF id=$INV_ID)" || ko "facture" "$INV"
in_list "invoices" "$INV_ID" "backend/invoices.php?action=list"

echo "== Devis =="
DEV=$(curl -s -c "$CJ" -b "$CJ" -X POST "$BASE/backend/quotes.php?action=create" \
  -H "Content-Type: application/json" \
  --data "{\"client_id\":$CLIENT_ID,\"ref\":\"\",\"status\":\"brouillon\",\"valid_until\":\"2026-10-05\",\"items\":[{\"product_id\":$PROD_ID,\"description\":\"Prestation de service\",\"quantity\":1,\"unit_price\":150,\"vat_rate\":20}],\"_csrf\":\"$TOKEN\"}")
DEV_ID=$(echo "$DEV" | "$PHP" -r '$d=json_decode(stream_get_contents(STDIN), true); echo $d["quote"]["id"] ?? $d["id"] ?? "";')
DEV_REF=$(echo "$DEV" | "$PHP" -r '$d=json_decode(stream_get_contents(STDIN), true); echo $d["quote"]["ref"] ?? $d["ref"] ?? "";')
echo "$DEV" | grep -q '"success":true' && ok "devis cree (ref=$DEV_REF id=$DEV_ID)" || ko "devis" "$DEV"
in_list "quotes" "$DEV_ID" "backend/quotes.php?action=list"

echo "== Paiement =="
PAY=$(curl -s -c "$CJ" -b "$CJ" -X POST "$BASE/backend/payments.php?action=create" \
  -H "Content-Type: application/json" \
  --data "{\"invoice_id\":$INV_ID,\"amount\":100,\"payment_method\":\"carte\",\"status\":\"paye\",\"transaction_ref\":\"E2E-001\",\"paid_at\":\"2026-08-06\",\"_csrf\":\"$TOKEN\"}")
PAY_ID=$(echo "$PAY" | "$PHP" -r '$d=json_decode(stream_get_contents(STDIN), true); echo $d["payment"]["id"] ?? $d["id"] ?? "";')
echo "$PAY" | grep -q '"success":true' && ok "paiement enregistre (id=$PAY_ID)" || ko "paiement" "$PAY"
in_list "payments" "$PAY_ID" "backend/payments.php?action=list"

STAT=$(curl -s -c "$CJ" -b "$CJ" -X POST "$BASE/backend/invoices.php?action=status" \
  -H "Content-Type: application/json" \
  --data "{\"id\":$INV_ID,\"status\":\"payee\",\"payment_method\":\"carte\",\"_csrf\":\"$TOKEN\"}")
echo "$STAT" | grep -q '"success":true' && ok "facture marquee payee" || ko "statut facture" "$STAT"

echo "== Depense =="
EXP=$(curl -s -c "$CJ" -b "$CJ" -X POST "$BASE/backend/expenses.php?action=create" \
  -H "Content-Type: application/json" \
  --data "{\"category\":\"Fournitures\",\"description\":\"Test E2E\",\"amount\":50,\"expense_date\":\"2026-08-06\",\"receipt_path\":\"\",\"_csrf\":\"$TOKEN\"}")
EXP_ID=$(echo "$EXP" | "$PHP" -r '$d=json_decode(stream_get_contents(STDIN), true); echo $d["expense"]["id"] ?? $d["id"] ?? "";')
echo "$EXP" | grep -q '"success":true' && ok "depense enregistree (id=$EXP_ID)" || ko "depense" "$EXP"
in_list "expenses" "$EXP_ID" "backend/expenses.php?action=list"

echo "== Stock =="
STK=$(curl -s -c "$CJ" -b "$CJ" -X POST "$BASE/backend/stock.php?action=create" \
  -H "Content-Type: application/json" \
  --data "{\"product_id\":$PROD_ID,\"quantity\":10,\"reorder_level\":3,\"_csrf\":\"$TOKEN\"}")
STK_ID=$(echo "$STK" | "$PHP" -r '$d=json_decode(stream_get_contents(STDIN), true); echo $d["stock"]["id"] ?? $d["id"] ?? "";')
echo "$STK" | grep -q '"success":true' && ok "entree de stock (id=$STK_ID)" || ko "stock" "$STK"
in_list "stock" "$STK_ID" "backend/stock.php?action=list"

echo "== Rapports (revenus) =="
REP=$(curl -s -c "$CJ" -b "$CJ" "$BASE/backend/reports.php?action=overview")
echo "$REP" | grep -q '"success":true' && ok "rapports accessibles" || ko "rapports" "$REP"
REV=$(echo "$REP" | "$PHP" -r '$d=json_decode(stream_get_contents(STDIN), true); echo $d["overview"]["total_revenue"] ?? "?";')
echo "     total_revenue (attendu 360) : $REV"
[ "$REV" = "360" ] && ok "revenus > 0 apres payee" || ko "revenus" "$REV"

echo "== Admin API doit etre BLOQUEE (non-admin) =="
CODE=$(curl -s -o /tmp/facto-admin.json -w "%{http_code}" -c "$CJ" -b "$CJ" "$BASE/backend/admin.php?action=overview")
echo "     HTTP $CODE: $(head -c 120 /tmp/facto-admin.json)"
[ "$CODE" = "403" ] && ok "admin API bloquee" || ko "admin API" "code=$CODE"

echo "== Abonnement doit etre free =="
SUB=$(curl -s -c "$CJ" -b "$CJ" "$BASE/backend/subscription.php?action=get")
echo "$SUB" | grep -qi 'free' && ok "plan gratuit" || ko "plan" "$SUB"

echo
echo "================ RESULTAT : $PASS OK / $FAIL ECHEC ================"
kill $SRV 2>/dev/null
exit $FAIL
