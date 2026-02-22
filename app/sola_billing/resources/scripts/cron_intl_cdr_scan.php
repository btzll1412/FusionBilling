<?php
/**
 * cron_intl_cdr_scan.php — Hourly CDR scanner for international calls.
 *
 * Reads v_xml_cdr, identifies international calls, looks up rates,
 * calculates charges, and stores them in v_sola_call_charges as 'pending'.
 *
 * These pending charges accumulate all month and are tallied at month-end
 * by the billing engine.
 *
 * Cron schedule: 0 * * * * (every hour)
 */

// Bootstrap FusionPBX
$document_root = realpath(dirname(__FILE__) . '/../../../..');
require_once $document_root . '/resources/require.php';
require_once 'resources/classes/database.php';

// Load billing classes
$classes_dir = dirname(__DIR__) . '/classes/';
require_once $classes_dir . 'BillingDatabase.php';
require_once $classes_dir . 'IntlRateTable.php';
require_once $classes_dir . 'CdrScanner.php';

echo "[" . date('Y-m-d H:i:s') . "] CDR Scanner starting...\n";

// Initialize
$billing_db = new BillingDatabase();

// Get unknown destination rate from settings
$unknown_rate = (float) ($billing_db->getSetting('unknown_dest_rate') ?? 0.10);

$rate_table = new IntlRateTable($billing_db, $unknown_rate);
$scanner = new CdrScanner($billing_db, $rate_table);

// Run the scan
$results = $scanner->scan();

echo "[" . date('Y-m-d H:i:s') . "] CDR Scanner completed:\n";
echo "  Scanned:  {$results['scanned']}\n";
echo "  Matched:  {$results['matched']}\n";
echo "  Charged:  {$results['charged']}\n";
echo "  Skipped:  {$results['skipped']}\n";

if (!empty($results['errors'])) {
    echo "  Errors:\n";
    foreach ($results['errors'] as $err) {
        echo "    - {$err}\n";
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Done.\n";
