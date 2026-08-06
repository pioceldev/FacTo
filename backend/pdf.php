<?php
require __DIR__ . '/db.php';
require __DIR__ . '/init.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$pdo = getPDO();
$userId = $_SESSION['user_id'];
$action = $_GET['action'] ?? 'invoice';

function esc($text) {
    return str_replace(['\\', '(', ')', "\n", "\r"], ['\\\\', '\\(', '\\)', ' ', ' '], (string)$text);
}

function fmt($amount) {
    return number_format((float)$amount, 0, ',', ' ') . ' FCFA';
}

class ProfessionalPDF {
    private $objects = [];
    private $objectCount = 0;
    private $pages = [];
    private $currentPage = '';
    private $y = 0;
    private $pageWidth = 595.28;
    private $pageHeight = 841.89;
    private $marginLeft = 50;
    private $marginRight = 50;
    private $contentWidth = 495.28;
    private $fontSize = 10;
    private $title = '';
    private $companyName = 'FacTo';
    private $companyAddress = '';
    private $logoText = '';
    private $primaryColor = [37, 99, 235];
    private $secondaryColor = [30, 64, 175];

    public function setTitle($title) { $this->title = $title; }
    public function setCompany($name, $address = '', $logo = '') {
        $this->companyName = $name;
        $this->companyAddress = $address;
        $this->logoText = $logo ?: $name;
    }
    public function setColors($primary, $secondary) {
        $this->primaryColor = $primary;
        $this->secondaryColor = $secondary;
    }

    private function newObj() {
        $this->objectCount++;
        return $this->objectCount;
    }

    private function addPageContent($content) {
        $this->pages[] = $content;
    }

    private function headerBlock() {
        $html = '';
        $html .= '<div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:20px;">';
        $html .= '<div>';
        $html .= '<div style="font-size:22px;font-weight:700;color:rgb(' . implode(',', $this->primaryColor) . ');margin-bottom:4px;">' . esc($this->companyName) . '</div>';
        if ($this->companyAddress) {
            $html .= '<div style="font-size:9px;color:#6b7280;">' . esc($this->companyAddress) . '</div>';
        }
        $html .= '</div>';
        $html .= '<div style="text-align:right;">';
        $html .= '<div style="font-size:18px;font-weight:700;color:#1f2937;">' . esc($this->title) . '</div>';
        $html .= '<div style="font-size:9px;color:#9ca3af;margin-top:4px;">Document généré le ' . date('d/m/Y H:i') . '</div>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '<hr style="border:none;border-top:3px solid rgb(' . implode(',', $this->primaryColor) . ');margin-bottom:16px;">';
        return $html;
    }

    private function sectionTitle($title) {
        return '<div style="font-size:12px;font-weight:700;color:rgb(' . implode(',', $this->secondaryColor) . ');margin-top:16px;margin-bottom:8px;padding-bottom:4px;border-bottom:1px solid #e5e7eb;">' . esc($title) . '</div>';
    }

    private function infoRow($label, $value) {
        return '<div style="display:flex;margin-bottom:3px;font-size:9.5px;"><span style="width:120px;font-weight:600;color:#6b7280;">' . esc($label) . '</span><span style="color:#1f2937;">' . esc($value) . '</span></div>';
    }

    private function tableHeader($cols, $widths) {
        $html = '<div style="display:flex;margin-top:12px;margin-bottom:4px;">';
        foreach ($cols as $i => $col) {
            $html .= '<div style="width:' . $widths[$i] . 'px;font-size:8px;font-weight:700;color:#fff;background:rgb(' . implode(',', $this->primaryColor) . ');padding:6px 8px;text-align:left;border-radius:4px 0 0 0;">' . esc($col) . '</div>';
        }
        $html .= '</div>';
        return $html;
    }

    private function tableRow($cells, $widths, $bold = false) {
        $html = '<div style="display:flex;margin-bottom:2px;">';
        foreach ($cells as $i => $cell) {
            $html .= '<div style="width:' . $widths[$i] . 'px;font-size:8.5px;padding:4px 8px;color:' . ($bold ? '#1f2937' : '#4b5563') . ';font-weight:' . ($bold ? '700' : '400') . ';">' . esc($cell) . '</div>';
        }
        $html .= '</div>';
        return $html;
    }

    private function tableTotal($label, $value, $widths) {
        $html = '<div style="display:flex;margin-top:8px;">';
        $html .= '<div style="width:' . $widths[0] . 'px;font-size:9px;font-weight:700;color:#1f2937;padding:6px 8px;text-align:right;border-top:2px solid rgb(' . implode(',', $this->primaryColor) . ');">(' . esc($label) . ')</div>';
        $html .= '<div style="width:' . $widths[1] . 'px;font-size:9px;font-weight:700;color:rgb(' . implode(',', $this->secondaryColor) . ');padding:6px 8px;border-top:2px solid rgb(' . implode(',', $this->primaryColor) . ');">' . esc($value) . '</div>';
        $html .= '</div>';
        return $html;
    }

    private function footerBlock($pageNum, $totalPages) {
        return '<div style="position:fixed;bottom:0;left:0;right:0;display:flex;justify-content:space-between;align-items:center;padding:8px 50px;font-size:8px;color:#9ca3af;border-top:1px solid #e5e7eb;">';
        $footer .= '<span>' . esc($this->companyName) . ' — Document confidentiel</span>';
        $footer .= '<span>Page ' . $pageNum . ' / ' . $totalPages . '</span>';
        $footer .= '</div>';
        return $footer;
    }

    public function renderInvoice($invoice, $items) {
        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>';
        $html .= 'body{font-family:"Segoe UI",Arial,sans-serif;margin:0;padding:40px 50px;color:#374151;font-size:10px;line-height:1.5;}';
        $html .= '</style></head><body>';
        $html .= $this->headerBlock();

        $html .= $this->sectionTitle('Informations de la facture');
        $html .= $this->infoRow('Référence', $invoice['ref']);
        $html .= $this->infoRow('Client', $invoice['client_name']);
        $html .= $this->infoRow('Email', $invoice['client_email'] ?? '-');
        $html .= $this->infoRow('Téléphone', $invoice['client_phone'] ?? '-');
        $html .= $this->infoRow('Adresse', ($invoice['client_address'] ?? '-') . ', ' . ($invoice['client_city'] ?? '') . ' ' . ($invoice['client_zip'] ?? ''));
        $html .= $this->infoRow('Statut', strtoupper($invoice['status']));
        $html .= $this->infoRow('Date d\'émission', $invoice['issued_at'] ? date('d/m/Y', strtotime($invoice['issued_at'])) : '-');
        $html .= $this->infoRow('Date d\'échéance', $invoice['due_date'] ? date('d/m/Y', strtotime($invoice['due_date'])) : '-');

        $html .= $this->sectionTitle('Détail des lignes');
        $colWidths = [220, 50, 70, 60, 95];
        $html .= $this->tableHeader(['Description', 'Qté', 'P.U.', 'TVA %', 'Total'], $colWidths);
        foreach ($items as $item) {
            $lineTotal = (float)$item['quantity'] * (float)$item['unit_price'];
            $html .= $this->tableRow([
                esc($item['description']),
                $item['quantity'] . ' ' . ($item['unit'] ?? 'unité'),
                fmt($item['unit_price']),
                $item['vat_rate'] . '%',
                fmt($lineTotal),
            ], $colWidths);
        }

        $html .= $this->sectionTitle('Récapitulatif');
        $html .= $this->tableTotal('Montant HT', fmt($invoice['amount']), [350, 145]);
        $html .= $this->tableTotal('TVA', fmt($invoice['vat']), [350, 145]);
        $html .= $this->tableTotal('TOTAL TTC', fmt($invoice['total']), [350, 145]);

        $html .= '<div style="margin-top:30px;padding-top:12px;border-top:1px solid #e5e7eb;font-size:8px;color:#9ca3af;text-align:center;">';
        $html .= 'Document généré par FacTo — Application de gestion d\'entreprise';
        $html .= '</div>';

        $html .= '</body></html>';
        return $html;
    }

    public function renderQuote($quote, $items) {
        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>';
        $html .= 'body{font-family:"Segoe UI",Arial,sans-serif;margin:0;padding:40px 50px;color:#374151;font-size:10px;line-height:1.5;}';
        $html .= '</style></head><body>';
        $html .= $this->headerBlock();

        $html .= $this->sectionTitle('Informations du devis');
        $html .= $this->infoRow('Référence', $quote['ref']);
        $html .= $this->infoRow('Client', $quote['client_name']);
        $html .= $this->infoRow('Email', $quote['client_email'] ?? '-');
        $html .= $this->infoRow('Téléphone', $quote['client_phone'] ?? '-');
        $html .= $this->infoRow('Adresse', ($quote['client_address'] ?? '-') . ', ' . ($quote['client_city'] ?? '') . ' ' . ($quote['client_zip'] ?? ''));
        $html .= $this->infoRow('Statut', strtoupper($quote['status']));
        $html .= $this->infoRow('Date de création', $quote['created_at'] ? date('d/m/Y', strtotime($quote['created_at'])) : '-');
        $html .= $this->infoRow('Date de validité', $quote['valid_until'] ? date('d/m/Y', strtotime($quote['valid_until'])) : '-');

        $html .= $this->sectionTitle('Détail des lignes');
        $colWidths = [220, 50, 70, 60, 95];
        $html .= $this->tableHeader(['Description', 'Qté', 'P.U.', 'TVA %', 'Total'], $colWidths);
        foreach ($items as $item) {
            $lineTotal = (float)$item['quantity'] * (float)$item['unit_price'];
            $html .= $this->tableRow([
                esc($item['description']),
                $item['quantity'] . ' ' . ($item['unit'] ?? 'unité'),
                fmt($item['unit_price']),
                $item['vat_rate'] . '%',
                fmt($lineTotal),
            ], $colWidths);
        }

        $html .= $this->sectionTitle('Récapitulatif');
        $html .= $this->tableTotal('Montant HT', fmt($quote['amount']), [350, 145]);
        $html .= $this->tableTotal('TVA', fmt($quote['vat']), [350, 145]);
        $html .= $this->tableTotal('TOTAL TTC', fmt($quote['total']), [350, 145]);

        $html .= '<div style="margin-top:30px;padding-top:12px;border-top:1px solid #e5e7eb;font-size:8px;color:#9ca3af;text-align:center;">';
        $html .= 'Document généré par FacTo — Application de gestion d\'entreprise';
        $html .= '</div>';

        $html .= '</body></html>';
        return $html;
    }
}

switch ($action) {
    case 'invoice':
        $invoiceId = intval($_GET['id'] ?? 0);
        if ($invoiceId <= 0) { http_response_code(400); echo json_encode(['error' => 'id requis']); exit; }

        $stmt = $pdo->prepare("SELECT i.*, c.name AS client_name, c.email AS client_email, c.phone AS client_phone, c.address AS client_address, c.city AS client_city, c.zip AS client_zip, c.country AS client_country FROM invoices i LEFT JOIN clients c ON i.client_id = c.id WHERE i.id = :id AND i.user_id = :uid");
        $stmt->execute([':id' => $invoiceId, ':uid' => $userId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) { http_response_code(404); echo json_encode(['error' => 'Facture introuvable']); exit; }

        $itemsStmt = $pdo->prepare("SELECT * FROM invoice_items WHERE invoice_id = :iid ORDER BY id");
        $itemsStmt->execute([':iid' => $invoiceId]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        $pdf = new ProfessionalPDF();
        $pdf->setTitle('Facture ' . $invoice['ref']);
        $pdf->setCompany('FacTo', 'Application de gestion d\'entreprise — Côte d\'Ivoire');
        $pdf->setColors([37, 99, 235], [30, 64, 175]);

        $html = $pdf->renderInvoice($invoice, $items);

        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: inline; filename="facture-' . $invoiceId . '.html"');
        header('Content-Length: ' . strlen($html));
        echo $html;
        exit;

    case 'quote':
        $quoteId = intval($_GET['id'] ?? 0);
        if ($quoteId <= 0) { http_response_code(400); echo json_encode(['error' => 'id requis']); exit; }

        $stmt = $pdo->prepare("SELECT q.*, c.name AS client_name, c.email AS client_email, c.phone AS client_phone, c.address AS client_address, c.city AS client_city, c.zip AS client_zip, c.country AS client_country FROM quotes q LEFT JOIN clients c ON q.client_id = c.id WHERE q.id = :id AND q.user_id = :uid");
        $stmt->execute([':id' => $quoteId, ':uid' => $userId]);
        $quote = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$quote) { http_response_code(404); echo json_encode(['error' => 'Devis introuvable']); exit; }

        $itemsStmt = $pdo->prepare("SELECT * FROM quote_items WHERE quote_id = :qid ORDER BY id");
        $itemsStmt->execute([':qid' => $quoteId]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        $pdf = new ProfessionalPDF();
        $pdf->setTitle('Devis ' . $quote['ref']);
        $pdf->setCompany('FacTo', 'Application de gestion d\'entreprise — Côte d\'Ivoire');
        $pdf->setColors([37, 99, 235], [30, 64, 175]);

        $html = $pdf->renderQuote($quote, $items);

        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: inline; filename="devis-' . $quoteId . '.html"');
        header('Content-Length: ' . strlen($html));
        echo $html;
        exit;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Action inconnue']);
}