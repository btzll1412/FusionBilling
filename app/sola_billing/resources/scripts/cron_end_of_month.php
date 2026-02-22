<?php
/**
 * cron_end_of_month.php — Fires consolidated monthly invoice charge.
 *
 * Runs at 11 PM on the 28th-31st, but only executes on the actual last
 * day of the month (checks if tomorrow is the 1st).
 *
 * For each enabled domain:
 * - Calculates subscription (extensions × rate)
 * - Tallies pending international call charges
 * - Generates one invoice
 * - Fires one consolidated charge
 * - Handles approve/decline
 *
 * Cron schedule: 0 23 28-31 * *
 */

// Bootstrap FusionPBX
$document_root = realpath(dirname(__FILE__) . '/../../../..');
require_once $document_root . '/resources/require.php';
require_once 'resources/classes/database.php';

// Only run on the actual last day of the month
if (date('j', strtotime('+1 day')) !== '1') {
    echo "[" . date('Y-m-d H:i:s') . "] Not the last day of the month. Exiting.\n";
    exit(0);
}

// Load billing classes
$classes_dir = dirname(__DIR__) . '/classes/';
require_once $classes_dir . 'BillingDatabase.php';
require_once $classes_dir . 'SolaGateway.php';
require_once $classes_dir . 'FailureHandler.php';
require_once $classes_dir . 'BillingEngine.php';

// Optional: InvoiceGenerator (only if available)
$invoice_gen = null;
if (file_exists($classes_dir . 'InvoiceGenerator.php')) {
    require_once $classes_dir . 'InvoiceGenerator.php';
    $billing_db_tmp = new BillingDatabase();
    $invoice_gen = new InvoiceGenerator($billing_db_tmp);
}

echo "[" . date('Y-m-d H:i:s') . "] End-of-month billing starting...\n";

// Initialize
$billing_db = new BillingDatabase();
$settings = $billing_db->getAllSettings();

$sandbox = ($settings['sandbox_mode'] ?? 'false') === 'true';
$api_key = $sandbox ? ($settings['api_key_sandbox'] ?? '') : ($settings['api_key'] ?? '');

if (empty($api_key)) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: No API key configured. Exiting.\n";
    $billing_db->writeAuditLog(null, 'cron', 'billing_aborted', ['reason' => 'no_api_key']);
    exit(1);
}

$gateway = new SolaGateway($api_key, $sandbox);
$failure_handler = new FailureHandler($billing_db);
$engine = new BillingEngine($billing_db, $gateway, $failure_handler, $invoice_gen);

// Run billing for all enabled domains
$results = $engine->runEndOfMonthBilling();

// Report
echo "[" . date('Y-m-d H:i:s') . "] Billing completed. Results:\n";
$approved = 0;
$declined = 0;
$skipped = 0;
$errors = 0;

foreach ($results as $r) {
    $status = $r['status'] ?? 'unknown';
    $name = $r['domain_name'] ?? $r['domain_uuid'] ?? '?';

    switch ($status) {
        case 'approved':
            $approved++;
            echo "  APPROVED: {$name} — \${$r['amount']} (Ref: {$r['xref_num']})\n";
            break;
        case 'declined':
            $declined++;
            echo "  DECLINED: {$name} — \${$r['amount']} — {$r['error']}\n";
            break;
        case 'skipped':
            $skipped++;
            echo "  SKIPPED:  {$name} — {$r['message']}\n";
            break;
        default:
            $errors++;
            echo "  ERROR:    {$name} — " . ($r['message'] ?? 'Unknown error') . "\n";
            break;
    }
}

echo "\nSummary: {$approved} approved, {$declined} declined, {$skipped} skipped, {$errors} errors\n";
echo "[" . date('Y-m-d H:i:s') . "] Done.\n";
