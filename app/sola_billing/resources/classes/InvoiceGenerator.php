<?php
/**
 * InvoiceGenerator — PDF invoice generation.
 *
 * Generates a PDF invoice with:
 * - Subscription line: X extensions × $Y.YY/ext = $ZZ.ZZ
 * - International calls: itemized list (date, destination, duration, cost)
 * - Total due
 * - Card charged info (after payment)
 *
 * Uses TCPDF if available, falls back to a plain HTML-to-file approach.
 */
class InvoiceGenerator {

    private BillingDatabase $db;
    private string $storage_path;
    private string $company_name;

    public function __construct(BillingDatabase $db) {
        $this->db = $db;
        $this->storage_path = $db->getSetting('invoice_storage_path')
                              ?? '/var/www/fusionpbx/storage/invoices/';
        $this->company_name = $db->getSetting('invoice_company_name') ?? 'FusionPBX';
    }

    /**
     * Generate a PDF invoice and save it to disk.
     *
     * @param string $domain_uuid
     * @param array  $data Invoice data with keys:
     *   invoice_number, domain_name, extension_count, rate_per_extension,
     *   subscription_amount, intl_charges (array), intl_total, invoice_total,
     *   billing_period, auth_code (optional), masked_card (optional)
     * @return string Path to the generated PDF file
     */
    public function generate(string $domain_uuid, array $data): string {
        // Ensure storage directory exists
        $dir = rtrim($this->storage_path, '/') . '/' . $domain_uuid;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = $data['invoice_number'] . '.pdf';
        $filepath = $dir . '/' . $filename;

        // Try TCPDF first
        if (class_exists('TCPDF') || $this->loadTcpdf()) {
            $this->generateWithTcpdf($filepath, $data);
        } else {
            // Fallback: save as HTML (can be printed/PDF'd from browser)
            $filepath = str_replace('.pdf', '.html', $filepath);
            $this->generateHtml($filepath, $data);
        }

        return $filepath;
    }

    /**
     * Attempt to load TCPDF.
     */
    private function loadTcpdf(): bool {
        $paths = [
            '/var/www/fusionpbx/vendor/tecnickcom/tcpdf/tcpdf.php',
            '/var/www/fusionpbx/vendor/autoload.php',
        ];
        foreach ($paths as $path) {
            if (file_exists($path)) {
                require_once $path;
                return class_exists('TCPDF');
            }
        }
        return false;
    }

    /**
     * Generate PDF using TCPDF.
     */
    private function generateWithTcpdf(string $filepath, array $data): void {
        $pdf = new TCPDF('P', 'mm', 'LETTER', true, 'UTF-8');
        $pdf->SetCreator('FusionPBX Sola Billing');
        $pdf->SetAuthor($this->company_name);
        $pdf->SetTitle('Invoice ' . $data['invoice_number']);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();

        $html = $this->buildInvoiceHtml($data);
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Output($filepath, 'F');
    }

    /**
     * Generate an HTML file as fallback.
     */
    private function generateHtml(string $filepath, array $data): void {
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8">';
        $html .= '<title>Invoice ' . htmlspecialchars($data['invoice_number']) . '</title>';
        $html .= '<style>
            body { font-family: Arial, sans-serif; font-size: 12px; margin: 40px; }
            table { border-collapse: collapse; width: 100%; }
            th, td { padding: 6px 10px; text-align: left; }
            .items th { background: #f0f0f0; border-bottom: 2px solid #333; }
            .items td { border-bottom: 1px solid #ddd; }
            .total { font-size: 14px; font-weight: bold; }
            .right { text-align: right; }
            h1 { color: #333; }
        </style></head><body>';
        $html .= $this->buildInvoiceHtml($data);
        $html .= '</body></html>';

        file_put_contents($filepath, $html);
    }

    /**
     * Build the invoice HTML content (used by both TCPDF and HTML fallback).
     */
    private function buildInvoiceHtml(array $data): string {
        $inv = htmlspecialchars($data['invoice_number']);
        $domain = htmlspecialchars($data['domain_name']);
        $period = htmlspecialchars($data['billing_period']);
        $ext_count = (int) $data['extension_count'];
        $rate = (float) $data['rate_per_extension'];
        $sub_amount = (float) $data['subscription_amount'];
        $intl_charges = $data['intl_charges'] ?? [];
        $intl_total = (float) ($data['intl_total'] ?? 0);
        $invoice_total = (float) $data['invoice_total'];

        $html = '';

        // Header
        $html .= '<h1>' . htmlspecialchars($this->company_name) . '</h1>';
        $html .= '<h2>Invoice: ' . $inv . '</h2>';
        $html .= '<table style="width: auto; margin-bottom: 20px;">';
        $html .= '<tr><td><strong>Domain:</strong></td><td>' . $domain . '</td></tr>';
        $html .= '<tr><td><strong>Billing Period:</strong></td><td>' . $period . '</td></tr>';
        $html .= '<tr><td><strong>Invoice Date:</strong></td><td>' . date('F j, Y') . '</td></tr>';
        $html .= '</table>';

        // Subscription line
        $html .= '<h3>Monthly Service — FusionPBX Hosted PBX</h3>';
        $html .= '<table class="items">';
        $html .= '<thead><tr><th>Description</th><th class="right">Amount</th></tr></thead>';
        $html .= '<tbody>';

        $html .= '<tr>';
        $html .= '<td>Subscription: ' . $ext_count . ' extensions × $' . number_format($rate, 2) . '/extension</td>';
        $html .= '<td class="right">$' . number_format($sub_amount, 2) . '</td>';
        $html .= '</tr>';

        // International calls (if any)
        if (!empty($intl_charges)) {
            $html .= '<tr><td colspan="2" style="padding-top: 15px;"><strong>International Calls:</strong></td></tr>';

            foreach ($intl_charges as $charge) {
                $call_date = substr($charge['call_date'] ?? '', 5, 5); // MM/DD
                $dest = htmlspecialchars($charge['destination_number'] ?? '');
                $country = htmlspecialchars($charge['country_name'] ?? '');
                $billsec = (int) ($charge['billsec'] ?? 0);
                $duration = sprintf('%dm %02ds', floor($billsec / 60), $billsec % 60);
                $rate_pm = number_format((float) ($charge['rate_per_minute'] ?? 0), 4);
                $amount = number_format((float) ($charge['charge_amount_rounded'] ?? 0), 2);

                $html .= '<tr>';
                $html .= '<td style="padding-left: 20px;">' . $call_date . '  ' . $dest . '  ' . $country . '  ' . $duration . '  $' . $rate_pm . '/min</td>';
                $html .= '<td class="right">$' . $amount . '</td>';
                $html .= '</tr>';
            }

            $html .= '<tr>';
            $html .= '<td style="padding-left: 20px;"><strong>International Calls Subtotal</strong></td>';
            $html .= '<td class="right"><strong>$' . number_format($intl_total, 2) . '</strong></td>';
            $html .= '</tr>';
        }

        // Divider and total
        $html .= '<tr style="border-top: 2px solid #333;">';
        $html .= '<td class="total">TOTAL DUE</td>';
        $html .= '<td class="right total">$' . number_format($invoice_total, 2) . '</td>';
        $html .= '</tr>';

        $html .= '</tbody></table>';

        // Payment info (if available)
        if (!empty($data['masked_card'])) {
            $html .= '<p style="margin-top: 20px;">';
            $html .= 'Charged to: ' . htmlspecialchars($data['masked_card']);
            if (!empty($data['auth_code'])) {
                $html .= ' &nbsp; Auth Code: ' . htmlspecialchars($data['auth_code']);
            }
            $html .= '</p>';
        }

        return $html;
    }
}
