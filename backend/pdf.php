<?php
/**
 * backend/pdf.php — Génération PDF des documents (factures).
 *
 * Actions :
 *   GET backend/pdf.php?action=invoice&id=123   => facture PDF (téléchargement)
 *
 * Design aligné sur le modèle « facture » de l'application (voir styles/invoices.css) :
 * entête émetteur avec logo, adresses, tableau des lignes, totaux HT/TVA/TTC,
 * remise éventuelle et signature de l'émetteur.
 */
require __DIR__ . '/db.php';
require __DIR__ . '/init.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$pdo = getPDO();
$userId = (int) $_SESSION['user_id'];
$action = $_GET['action'] ?? '';

if ($action !== 'invoice') {
    http_response_code(400);
    echo json_encode(['error' => 'Action inconnue']);
    exit;
}

require __DIR__ . '/../vendor/fpdf/fpdf.php';

/** Convertit une valeur UTF-8 en latin-1 pour FPDF (police core Helvetica). */
function pdfText($value)
{
    return mb_convert_encoding((string) $value, 'ISO-8859-1', 'UTF-8');
}

/** Formate un montant selon la devise de l'utilisateur (EUR | USD | XOF). */
function pdfMoney($amount, $currency = 'EUR')
{
    $amount = (float) $amount;
    $sign = $amount < 0 ? '-' : '';
    $abs = abs($amount);
    switch (strtoupper($currency)) {
        case 'USD':
            return $sign . number_format($abs, 2, ',', ' ') . ' $';
        case 'XOF':
            return $sign . number_format($abs, 0, ',', ' ') . ' FCFA';
        case 'EUR':
        default:
            return $sign . number_format($abs, 2, ',', ' ') . ' €';
    }
}

class FacToInvoicePdf extends FPDF
{
    private $accent = [249, 115, 22];   // orange FacTo
    private $dark   = [17, 24, 39];
    private $muted  = [107, 114, 128];
    public $currency = 'EUR';            // devise d'affichage (EUR | USD | XOF)

    public function header()
    {
        // Rien en en-tête automatique : l'entête du document est dessiné manuellement.
    }

    public function footer()
    {
        $this->SetY(-16);
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(...$this->muted);
        $this->Cell(0, 6, pdfText('FacTo — Document généré le ' . date('d/m/Y à H:i')), 0, 0, 'C');
        $this->SetY(-22);
        $this->SetDrawColor(...$this->accent);
        $this->SetLineWidth(0.4);
        $this->Line(12, $this->GetY(), 198, $this->GetY());
    }

    /** Bandeau du document : logo / émetteur à gauche, « FACTURE » + réf à droite. */
    public function docHeader(array $issuer, array $inv)
    {
        $this->SetFont('Helvetica', 'B', 20);
        $this->SetTextColor(...$this->dark);
        $this->SetXY(12, 12);

        // Logo si disponible (webp/png/jpg), sinon initiale dans une pastille
        $logo = null;
        if (!empty($issuer['logo_path'])) {
            $abs = __DIR__ . '/../' . ltrim($issuer['logo_path'], '/');
            if (is_file($abs)) {
                $logo = $abs;
            }
        }

        if ($logo) {
            $this->Image($logo, 12, 12, 22, 0);
            $this->SetXY(38, 14);
        } else {
            $initial = strtoupper(substr(trim($issuer['company'] ?: $issuer['name'] ?: 'F'), 0, 1));
            $this->SetFillColor(...$this->accent);
            $this->SetTextColor(255, 255, 255);
            $this->Rect(12, 12, 22, 22, 'F');
            $this->SetXY(12, 15);
            $this->SetFont('Helvetica', 'B', 16);
            $this->Cell(22, 12, pdfText($initial), 0, 0, 'C');
            $this->SetXY(38, 14);
            $this->SetTextColor(...$this->dark);
        }

        $this->SetFont('Helvetica', 'B', 15);
        $this->Cell(0, 8, pdfText($issuer['company'] ?: $issuer['name'] ?: 'FacTo'), 0, 1);
        $this->SetFont('Helvetica', '', 9);
        $this->SetTextColor(...$this->muted);
        if (!empty($issuer['email'])) {
            $this->SetX(38);
            $this->Cell(0, 5, pdfText($issuer['email']), 0, 1);
        }
        if (!empty($issuer['phone'])) {
            $this->SetX(38);
            $this->Cell(0, 5, pdfText($issuer['phone']), 0, 1);
        }

        // Bloc « FACTURE » à droite
        $this->SetFont('Helvetica', 'B', 26);
        $this->SetTextColor(...$this->accent);
        $this->SetXY(120, 14);
        $this->Cell(78, 12, pdfText('FACTURE'), 0, 1, 'R');

        $this->SetFont('Helvetica', 'B', 10);
        $this->SetTextColor(...$this->dark);
        $this->SetX(120);
        $this->Cell(78, 7, pdfText('N° ' . $inv['ref']), 0, 1, 'R');
        $this->SetFont('Helvetica', '', 9);
        $this->SetTextColor(...$this->muted);
        $this->SetX(120);
        $this->Cell(78, 6, pdfText('Émise le ' . date('d/m/Y', strtotime($inv['issued_at'] ?: $inv['created_at']))), 0, 1, 'R');
        $this->SetX(120);
        $this->Cell(78, 6, pdfText('Échéance : ' . date('d/m/Y', strtotime($inv['due_date']))), 0, 1, 'R');

        $this->SetDrawColor(...$this->accent);
        $this->SetLineWidth(0.6);
        $this->Line(12, $this->GetY() + 6, 198, $this->GetY() + 6);
        $this->SetY($this->GetY() + 14);
    }

    /** Adresses émetteur / client. */
    public function addresses(array $issuer, array $inv)
    {
        $this->SetFont('Helvetica', 'B', 9);
        $this->SetTextColor(...$this->muted);
        $this->Cell(93, 6, pdfText('ADRESSE / ÉMETTEUR'), 0, 0);
        $this->Cell(93, 6, pdfText('FACTURÉ À'), 0, 1);

        $this->SetFont('Helvetica', 'B', 10);
        $this->SetTextColor(...$this->dark);
        $company = $issuer['company'] ?: $issuer['name'] ?: 'FacTo';
        $this->Cell(93, 7, pdfText($company), 0, 0);

        $clientName = $inv['client_name'] ?: 'Client ponctuel';
        $this->Cell(93, 7, pdfText($clientName), 0, 1);

        $this->SetFont('Helvetica', '', 9);
        $this->SetTextColor(...$this->muted);

        // Colonne gauche (émetteur) : Y départ
        $leftY = $this->GetY();
        $left = [];
        if (!empty($issuer['phone'])) $left[] = $issuer['phone'];
        if (!empty($issuer['email'])) $left[] = $issuer['email'];
        $this->SetXY(12, $leftY);
        $this->MultiCell(93, 5, pdfText(implode("\n", $left)), 0, 'L', false);
        $leftBottom = $this->GetY();

        // Colonne droite (client) : Y départ identique, indépendant de la hauteur gauche
        $rightY = $leftY;
        $right = [];
        if (!empty($inv['client_company'])) $right[] = $inv['client_company'];
        if (!empty($inv['client_email'])) $right[] = $inv['client_email'];
        if (!empty($inv['client_phone'])) $right[] = $inv['client_phone'];
        if (!empty($inv['client_address'])) {
            $addr = $inv['client_address'];
            if (!empty($inv['client_city'])) $addr .= ', ' . $inv['client_city'];
            $right[] = $addr;
        }
        $this->SetXY(105, $rightY);
        $this->MultiCell(93, 5, pdfText(implode("\n", $right)), 0, 'L', false);
        $rightBottom = $this->GetY();

        $this->SetY(max($leftBottom, $rightBottom) + 10);
    }

    /** Tableau des lignes. */
    public function itemsTable(array $items)
    {
        $col = [12, 72, 30, 26, 58];
        $w = array_sum($col);

        $this->SetFillColor(249, 115, 22);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Helvetica', 'B', 8.5);
        $this->Cell($col[0], 9, pdfText('#'), 0, 0, 'C', true);
        $this->Cell($col[1], 9, pdfText('Désignation'), 0, 0, 'L', true);
        $this->Cell($col[2], 9, pdfText('Qté'), 0, 0, 'C', true);
        $this->Cell($col[3], 9, pdfText('P.U. HT'), 0, 0, 'R', true);
        $this->Cell($col[4], 9, pdfText('Total HT'), 0, 1, 'R', true);

        $this->SetFont('Helvetica', '', 9);
        $this->SetTextColor(...$this->dark);
        $fill = false;
        $i = 1;
        foreach ($items as $item) {
            if ($this->GetY() > 250) {
                $this->AddPage();
            }
            $this->SetFillColor(249, 250, 251);
            $this->Cell($col[0], 8, pdfText($i), 0, 0, 'C', $fill);
            $this->Cell($col[1], 8, pdfText($item['description'] ?: '—'), 0, 0, 'L', $fill);
            $this->Cell($col[2], 8, pdfText($item['quantity']), 0, 0, 'C', $fill);
            $this->Cell($col[3], 8, pdfText(number_format((float) $item['unit_price'], 2, ',', ' ')), 0, 0, 'R', $fill);
            $this->Cell($col[4], 8, pdfText(number_format((float) $item['line_total'], 2, ',', ' ')), 0, 1, 'R', $fill);
            $fill = !$fill;
            $i++;
        }

        $this->SetDrawColor(...$this->accent);
        $this->SetLineWidth(0.4);
        $this->Line(12, $this->GetY(), 198, $this->GetY());
    }

    /** Totaux HT / TVA / remise / TTC. */
    public function totals(array $inv)
    {
        $this->SetY($this->GetY() + 8);
        $x = 108;
        $w = 90;

        $lines = [
            ['Sous-total HT', (float) $inv['amount']],
            ['TVA', (float) $inv['vat']],
        ];
        if ((float) $inv['discount'] > 0) {
            $lines[] = ['Remise', -1 * (float) $inv['discount']];
        }
        foreach ($lines as $line) {
            $this->SetFont('Helvetica', '', 9.5);
            $this->SetTextColor(...$this->muted);
            $this->SetX($x);
            $this->Cell($w - 40, 6, pdfText($line[0]), 0, 0);
            $this->SetFont('Helvetica', 'B', 9.5);
            $this->SetTextColor(...$this->dark);
            $this->Cell(40, 6, pdfText(pdfMoney($line[1], $this->currency)), 0, 1, 'R');
        }

        // Total TTC en surbrillance
        $this->SetFillColor(249, 115, 22);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Helvetica', 'B', 12);
        $this->SetX($x - 8);
        $this->Cell($w + 8, 11, pdfText('TOTAL TTC'), 0, 0, 'L', true);
        $this->SetX($x - 8 + 50);
        $this->Cell($w - 50 + 8, 11, pdfText(pdfMoney($inv['total'], $this->currency)), 0, 1, 'R', true);
    }

    /** Note + signature. */
    public function noteAndSignature(array $issuer, array $inv)
    {
        $this->SetY($this->GetY() + 14);
        if (!empty($inv['notes'])) {
            $this->SetFont('Helvetica', 'B', 9);
            $this->SetTextColor(...$this->muted);
            $this->Cell(0, 6, pdfText('NOTE'), 0, 1);
            $this->SetFont('Helvetica', '', 9);
            $this->SetTextColor(...$this->dark);
            $this->MultiCell(120, 5, pdfText($inv['notes']), 0, 'L');
        }

        $y = $this->GetY() + 12;
        if ($y < 210) $y = 210;
        $this->SetY($y);

        $this->SetFont('Helvetica', 'B', 9);
        $this->SetTextColor(...$this->muted);
        $this->Cell(60, 6, pdfText('SIGNATURE'), 0, 1);

        // Signature image (data URL) si disponible
        if (!empty($issuer['signature'])) {
            $sig = $issuer['signature'];
            if (preg_match('#^data:image/(png|jpeg|webp);base64,(.+)$#i', $sig, $m)) {
                $tmp = tempnam(sys_get_temp_dir(), 'sig') . '.' . $m[1];
                file_put_contents($tmp, base64_decode($m[2]));
                try {
                    $this->Image($tmp, 12, $this->GetY(), 40, 0);
                } catch (Exception $e) {
                    // image illisible : on ignore, le nom reste affiché
                }
                @unlink($tmp);
                $this->SetY($this->GetY() + 26);
            }
        }

        $this->SetFont('Helvetica', 'B', 11);
        $this->SetTextColor(...$this->dark);
        $this->Cell(0, 7, pdfText($issuer['name'] ?: $issuer['company'] ?: ''), 0, 1);
        $this->SetFont('Helvetica', '', 8.5);
        $this->SetTextColor(...$this->muted);
        $this->Cell(0, 5, pdfText(!empty($issuer['signature']) ? 'Signature' : 'Non signé'), 0, 1);
    }
}

// Récupère la facture + émetteur
$invoiceId = (int) ($_GET['id'] ?? 0);
if (!$invoiceId) {
    http_response_code(422);
    echo json_encode(['error' => 'Identifiant de facture manquant']);
    exit;
}

$stmt = $pdo->prepare(
    'SELECT i.*, COALESCE(c.name, "Client ponctuel") AS client_name,
            c.email AS client_email, c.company AS client_company,
            c.phone AS client_phone, c.address AS client_address, c.city AS client_city
     FROM invoices i
     LEFT JOIN clients c ON c.id = i.client_id
     WHERE i.id = ? AND i.user_id = ?'
);
$stmt->execute([$invoiceId, $userId]);
$invoice = $stmt->fetch();

if (!$invoice) {
    http_response_code(404);
    echo json_encode(['error' => 'Facture introuvable']);
    exit;
}

$itemsStmt = $pdo->prepare('SELECT description, quantity, unit_price, line_total FROM invoice_items WHERE invoice_id = ? ORDER BY id');
$itemsStmt->execute([$invoiceId]);
$invoice['items'] = $itemsStmt->fetchAll();

$userStmt = $pdo->prepare('SELECT name, email, company, phone, logo_path, signature, currency FROM users WHERE id = ?');
$userStmt->execute([$userId]);
$issuer = $userStmt->fetch() ?: [];

$pdf = new FacToInvoicePdf();
$pdf->currency = in_array(strtoupper($issuer['currency'] ?? 'EUR'), ['EUR', 'USD', 'XOF'], true)
    ? strtoupper($issuer['currency']) : 'EUR';
$pdf->SetMargins(12, 12, 12);
$pdf->SetAutoPageBreak(true, 18);
$pdf->AddPage();
$pdf->docHeader($issuer, $invoice);
$pdf->addresses($issuer, $invoice);
$pdf->itemsTable($invoice['items']);
$pdf->totals($invoice);
$pdf->noteAndSignature($issuer, $invoice);

$filename = preg_replace('/[^A-Za-z0-9\-_]/', '_', $invoice['ref']) ?: 'facture';
// Output('D') gère lui-même les en-têtes Content-Type et Content-Disposition (téléchargement).
$pdf->Output('D', $filename . '.pdf');
exit;
