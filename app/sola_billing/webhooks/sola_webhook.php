<?php
/**
 * sola_webhook.php — Sola/Cardknox webhook receiver.
 *
 * Receives POST notifications from Sola for transaction events.
 * Logs all incoming webhook data to the audit log for monitoring.
 *
 * URL to configure in Sola portal:
 *   https://your-fusionpbx.example.com/app/sola_billing/webhooks/sola_webhook.php
 */

// Bootstrap FusionPBX
$document_root = realpath(dirname(__FILE__) . '/../../..');
require_once $document_root . '/resources/require.php';
require_once 'resources/classes/database.php';

require_once dirname(__DIR__) . '/resources/classes/BillingDatabase.php';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Read raw POST body
$raw_body = file_get_contents('php://input');
$data = json_decode($raw_body, true);

if (!$data) {
    // Try form-encoded
    $data = $_POST;
}

if (empty($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'No data received']);
    exit;
}

// Initialize database
$billing_db = new BillingDatabase();

// Log the webhook
$billing_db->writeAuditLog(
    null,
    'webhook',
    'sola_webhook_received',
    [
        'payload'    => $data,
        'headers'    => getallheaders(),
        'ip'         => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    ],
    $_SERVER['REMOTE_ADDR'] ?? null
);

// Process webhook events
$event_type = $data['xEvent'] ?? $data['event'] ?? $data['Event'] ?? 'unknown';
$xref_num = $data['xRefNum'] ?? $data['RefNum'] ?? null;

switch (strtolower($event_type)) {
    case 'settlement':
    case 'batch_settled':
        // Batch settlement notification — informational only
        $billing_db->writeAuditLog(null, 'webhook', 'batch_settled', [
            'batch_id' => $data['xBatchNum'] ?? null,
            'count'    => $data['xTransactionCount'] ?? null,
        ]);
        break;

    case 'chargeback':
    case 'dispute':
        // Chargeback notification — flag for admin attention
        if ($xref_num) {
            // Find the domain associated with this transaction
            $db = new database;
            $db->execute(
                "SELECT domain_uuid FROM v_sola_transactions WHERE xref_num = :xref LIMIT 1",
                [':xref' => (int) $xref_num]
            );
            $rows = $db->result();
            $domain_uuid = !empty($rows) ? $rows[0]['domain_uuid'] : null;

            $billing_db->writeAuditLog(
                $domain_uuid,
                'webhook',
                'chargeback_received',
                [
                    'xref_num' => $xref_num,
                    'amount'   => $data['xAmount'] ?? null,
                    'reason'   => $data['xReason'] ?? $data['Reason'] ?? null,
                ]
            );

            // Send alert to admin
            $admin_email = $billing_db->getSetting('admin_email');
            if (!empty($admin_email)) {
                $subject = "CHARGEBACK ALERT — xRefNum: {$xref_num}";
                $body = "A chargeback/dispute has been filed.\n\n";
                $body .= "Reference: {$xref_num}\n";
                $body .= "Amount: " . ($data['xAmount'] ?? 'N/A') . "\n";
                $body .= "Reason: " . ($data['xReason'] ?? $data['Reason'] ?? 'N/A') . "\n\n";
                $body .= "Review this in your Sola/Cardknox portal immediately.\n";
                $body .= "— FusionPBX Sola Billing System";

                $emails = array_map('trim', explode(',', $admin_email));
                foreach ($emails as $email) {
                    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        @mail($email, $subject, $body, "From: noreply@" . gethostname());
                    }
                }
            }
        }
        break;

    default:
        // Unknown event — just log it
        break;
}

// Acknowledge receipt
http_response_code(200);
echo json_encode(['status' => 'ok', 'event' => $event_type]);
