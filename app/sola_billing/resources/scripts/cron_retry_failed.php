<?php
/**
 * cron_retry_failed.php — Retries failed charges.
 *
 * Runs daily at 8 AM. Checks for domains that:
 * - Have a failed charge (last_charge_result = D or E)
 * - Are due for retry (next_retry_at <= now)
 * - Haven't exhausted retry attempts
 *
 * Retry schedule: every 3 days (configurable), max 3 attempts.
 * After 3 failures: mark abandoned, alert admin.
 *
 * Cron schedule: 0 8 * * *
 */

// Bootstrap FusionPBX
$document_root = realpath(dirname(__FILE__) . '/../../../..');
require_once $document_root . '/resources/require.php';
require_once 'resources/classes/database.php';

// Load billing classes
$classes_dir = dirname(__DIR__) . '/classes/';
require_once $classes_dir . 'BillingDatabase.php';
require_once $classes_dir . 'SolaGateway.php';
require_once $classes_dir . 'FailureHandler.php';
require_once $classes_dir . 'BillingEngine.php';

// Optional: InvoiceGenerator
$invoice_gen = null;
if (file_exists($classes_dir . 'InvoiceGenerator.php')) {
    require_once $classes_dir . 'InvoiceGenerator.php';
    $billing_db_tmp = new BillingDatabase();
    $invoice_gen = new InvoiceGenerator($billing_db_tmp);
}

echo "[" . date('Y-m-d H:i:s') . "] Retry failed charges starting...\n";

// Initialize
$billing_db = new BillingDatabase();
$settings = $billing_db->getAllSettings();

$sandbox = ($settings['sandbox_mode'] ?? 'false') === 'true';
$api_key = $sandbox ? ($settings['api_key_sandbox'] ?? '') : ($settings['api_key'] ?? '');

if (empty($api_key)) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: No API key configured. Exiting.\n";
    exit(1);
}

$gateway = new SolaGateway($api_key, $sandbox);
$failure_handler = new FailureHandler($billing_db);
$engine = new BillingEngine($billing_db, $gateway, $failure_handler, $invoice_gen);

// Get domains due for retry
$domains_to_retry = $failure_handler->getDomainsForRetry();

if (empty($domains_to_retry)) {
    echo "[" . date('Y-m-d H:i:s') . "] No domains due for retry.\n";
    exit(0);
}

echo "[" . date('Y-m-d H:i:s') . "] Found " . count($domains_to_retry) . " domain(s) to retry.\n";

foreach ($domains_to_retry as $domain) {
    $d_uuid = $domain['domain_uuid'];
    $d_name = $domain['domain_name'] ?? $d_uuid;
    $retry_num = ((int) ($domain['retry_count'] ?? 0)) + 1;

    echo "  Retrying: {$d_name} (attempt #{$retry_num})... ";

    $result = $engine->retryFailedDomain($d_uuid);
    $status = $result['status'] ?? 'unknown';

    if ($status === 'approved') {
        echo "APPROVED — \$" . number_format($result['amount'] ?? 0, 2) . "\n";
    } elseif ($status === 'declined') {
        echo "DECLINED — " . ($result['error'] ?? 'Unknown') . "\n";
    } else {
        echo strtoupper($status) . " — " . ($result['message'] ?? '') . "\n";
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Done.\n";
