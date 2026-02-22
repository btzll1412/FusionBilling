<?php
/**
 * transaction_detail.php — Single transaction detail + refund/void actions.
 *
 * Shows full record including all Sola response fields, linked domain,
 * billing period, extension count snapshot, related call charges.
 */

// FusionPBX bootstrap
require_once dirname(__DIR__, 3) . '/resources/require.php';
require_once 'resources/check_auth.php';

if (!permission_exists('sola_billing_transactions')) {
    echo "access denied";
    exit;
}

$classes_dir = dirname(__DIR__) . '/resources/classes/';
require_once $classes_dir . 'BillingDatabase.php';
require_once $classes_dir . 'SolaGateway.php';
$billing_db = new BillingDatabase();

$transaction_uuid = $_GET['transaction_uuid'] ?? '';
if (empty($transaction_uuid)) {
    echo "No transaction specified.";
    exit;
}

$message = '';
$error = '';

// Handle refund/void actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['token']) && validate_token($_POST['token'])) {
    $action = $_POST['action'] ?? '';
    $settings = $billing_db->getAllSettings();
    $sandbox = ($settings['sandbox_mode'] ?? 'false') === 'true';
    $api_key = $sandbox ? ($settings['api_key_sandbox'] ?? '') : ($settings['api_key'] ?? '');

    if (empty($api_key)) {
        $error = 'No API key configured.';
    } else {
        $gateway = new SolaGateway($api_key, $sandbox);
        $txn = $billing_db->getTransaction($transaction_uuid);

        if (!$txn) {
            $error = 'Transaction not found.';
        } else {
            $xref = (string) ($txn['xref_num'] ?? '');

            switch ($action) {
                case 'void':
                    if (empty($xref)) {
                        $error = 'No reference number to void.';
                    } else {
                        $result = $gateway->void($xref);
                        if ($gateway->isApproved($result)) {
                            $billing_db->createTransaction([
                                'domain_uuid'     => $txn['domain_uuid'],
                                'method_uuid'     => $txn['method_uuid'],
                                'charge_type'     => 'void',
                                'xref_num'        => $gateway->getRefNum($result),
                                'x_invoice'       => $txn['x_invoice'],
                                'amount'          => $txn['amount'],
                                'result'          => 'A',
                                'result_message'  => 'Voided',
                                'parent_xref_num' => (int) $xref,
                                'charged_by'      => $_SESSION['username'] ?? 'admin',
                                'ip_address'      => $_SERVER['REMOTE_ADDR'] ?? null,
                            ]);
                            $message = "Transaction voided successfully.";

                            $billing_db->writeAuditLog(
                                $txn['domain_uuid'],
                                $_SESSION['username'] ?? 'admin',
                                'void_issued',
                                ['original_xref' => $xref, 'amount' => $txn['amount']],
                                $_SERVER['REMOTE_ADDR'] ?? null
                            );
                        } else {
                            $error = 'Void failed: ' . $gateway->getErrorMessage($result) .
                                     '. The transaction may already be settled. Try a refund instead.';
                        }
                    }
                    break;

                case 'refund':
                    $refund_amount = (float) ($_POST['refund_amount'] ?? 0);
                    if ($refund_amount <= 0 || $refund_amount > (float) $txn['amount']) {
                        $error = 'Invalid refund amount.';
                    } elseif (empty($xref)) {
                        $error = 'No reference number to refund.';
                    } else {
                        $result = $gateway->refund($xref, $refund_amount);
                        if ($gateway->isApproved($result)) {
                            $ref_invoice = 'REF-' . ($txn['x_invoice'] ?? '');
                            $billing_db->createTransaction([
                                'domain_uuid'     => $txn['domain_uuid'],
                                'method_uuid'     => $txn['method_uuid'],
                                'charge_type'     => 'refund',
                                'xref_num'        => $gateway->getRefNum($result),
                                'x_invoice'       => $ref_invoice,
                                'amount'          => $refund_amount,
                                'result'          => 'A',
                                'result_message'  => 'Refunded',
                                'parent_xref_num' => (int) $xref,
                                'charged_by'      => $_SESSION['username'] ?? 'admin',
                                'ip_address'      => $_SERVER['REMOTE_ADDR'] ?? null,
                            ]);
                            $message = "Refund of \$" . number_format($refund_amount, 2) . " processed successfully.";

                            $billing_db->writeAuditLog(
                                $txn['domain_uuid'],
                                $_SESSION['username'] ?? 'admin',
                                'refund_issued',
                                ['original_xref' => $xref, 'refund_amount' => $refund_amount],
                                $_SERVER['REMOTE_ADDR'] ?? null
                            );
                        } else {
                            $error = 'Refund failed: ' . $gateway->getErrorMessage($result);
                        }
                    }
                    break;
            }
        }
    }
}

// Load transaction
$txn = $billing_db->getTransaction($transaction_uuid);
if (!$txn) {
    echo "Transaction not found.";
    exit;
}

// Get related call charges (for monthly invoices)
$related_charges = [];
if ($txn['charge_type'] === 'monthly_invoice' && $txn['result'] === 'A') {
    $db = new database;
    $db->execute(
        "SELECT * FROM v_sola_call_charges
         WHERE transaction_uuid = :txn_uuid ORDER BY call_date",
        [':txn_uuid' => $transaction_uuid]
    );
    $related_charges = $db->result() ?: [];
}

$document['title'] = 'Transaction Detail — ' . ($txn['x_invoice'] ?? $transaction_uuid);
require_once 'resources/header.php';

$is_approved = ($txn['result'] ?? '') === 'A';
$charge_type = $txn['charge_type'] ?? '';
$is_same_day = (substr($txn['created_at'] ?? '', 0, 10) === date('Y-m-d'));

?>

<div class="action_bar" id="action_bar">
    <div class="heading">
        <b>Transaction Detail</b>
    </div>
    <div class="actions">
        <a href="transactions.php" class="btn btn-default btn-sm">
            <span class="fas fa-arrow-left"></span> Back
        </a>
    </div>
</div>

<?php if (!empty($message)): ?>
<div class="alert alert-success" style="margin: 10px 0;"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
<div class="alert alert-danger" style="margin: 10px 0;"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<!-- Transaction Info -->
<table class="table" style="width: auto;">
    <tr><td class="vncell" style="width: 200px;">Transaction UUID</td><td class="vtable"><code><?php echo htmlspecialchars($txn['transaction_uuid']); ?></code></td></tr>
    <tr><td class="vncell">Domain</td><td class="vtable"><?php echo htmlspecialchars($txn['domain_name'] ?? ''); ?></td></tr>
    <tr><td class="vncell">Charge Type</td><td class="vtable"><?php echo htmlspecialchars(str_replace('_', ' ', $charge_type)); ?></td></tr>
    <tr><td class="vncell">Invoice Number</td><td class="vtable"><?php echo htmlspecialchars($txn['x_invoice'] ?? ''); ?></td></tr>
    <tr><td class="vncell">Amount</td><td class="vtable"><strong>$<?php echo number_format((float) ($txn['amount'] ?? 0), 2); ?></strong></td></tr>
    <tr>
        <td class="vncell">Result</td>
        <td class="vtable">
            <?php
                $r = $txn['result'] ?? '';
                if ($r === 'A') echo '<span style="color: #5cb85c; font-weight: bold;">Approved</span>';
                elseif ($r === 'D') echo '<span style="color: #d9534f; font-weight: bold;">Declined</span>';
                elseif ($r === 'E') echo '<span style="color: #f0ad4e; font-weight: bold;">Error</span>';
            ?>
        </td>
    </tr>
    <tr><td class="vncell">Result Message</td><td class="vtable"><?php echo htmlspecialchars($txn['result_message'] ?? ''); ?></td></tr>
    <tr><td class="vncell">xRefNum</td><td class="vtable"><code><?php echo htmlspecialchars($txn['xref_num'] ?? ''); ?></code></td></tr>
    <tr><td class="vncell">Auth Code</td><td class="vtable"><?php echo htmlspecialchars($txn['auth_code'] ?? ''); ?></td></tr>
    <tr><td class="vncell">Card</td><td class="vtable"><?php echo htmlspecialchars(($txn['card_type'] ?? '') . ' ' . ($txn['masked_card'] ?? '')); ?></td></tr>
    <?php if (!empty($txn['extension_count'])): ?>
    <tr><td class="vncell">Extensions (snapshot)</td><td class="vtable"><?php echo (int) $txn['extension_count']; ?></td></tr>
    <?php endif; ?>
    <?php if (!empty($txn['billing_period_start'])): ?>
    <tr><td class="vncell">Billing Period</td><td class="vtable"><?php echo htmlspecialchars($txn['billing_period_start'] . ' to ' . $txn['billing_period_end']); ?></td></tr>
    <?php endif; ?>
    <?php if (!empty($txn['parent_xref_num'])): ?>
    <tr><td class="vncell">Parent xRefNum</td><td class="vtable"><code><?php echo htmlspecialchars($txn['parent_xref_num']); ?></code></td></tr>
    <?php endif; ?>
    <tr><td class="vncell">Charged By</td><td class="vtable"><?php echo htmlspecialchars($txn['charged_by'] ?? ''); ?></td></tr>
    <tr><td class="vncell">IP Address</td><td class="vtable"><?php echo htmlspecialchars($txn['ip_address'] ?? ''); ?></td></tr>
    <tr><td class="vncell">Date</td><td class="vtable"><?php echo htmlspecialchars($txn['created_at'] ?? ''); ?></td></tr>
</table>

<!-- Actions (Void / Refund) -->
<?php if ($is_approved && in_array($charge_type, ['monthly_invoice', 'manual'])): ?>
<h4 style="margin-top: 30px;">Actions</h4>
<div style="display: flex; gap: 20px;">
    <?php if ($is_same_day): ?>
    <!-- Void (same-day only) -->
    <form method="post">
        <input type="hidden" name="action" value="void">
        <input type="hidden" name="token" value="<?php echo create_token(); ?>">
        <button type="submit" class="btn btn-warning btn-sm"
                onclick="return confirm('Void this transaction? This reverses the full charge before settlement.');">
            <span class="fas fa-undo"></span> Void Transaction
        </button>
    </form>
    <?php else: ?>
    <div>
        <button type="button" class="btn btn-default btn-sm" disabled
                title="Void is only available on the same day as the charge (before batch settlement)">
            <span class="fas fa-undo"></span> Void (settled)
        </button>
        <br><small style="color: #999;">Transaction already settled. Use refund instead.</small>
    </div>
    <?php endif; ?>

    <!-- Refund -->
    <form method="post" style="display: flex; align-items: center; gap: 10px;">
        <input type="hidden" name="action" value="refund">
        <input type="hidden" name="token" value="<?php echo create_token(); ?>">
        $<input type="number" name="refund_amount" class="formfld" step="0.01" min="0.01"
               max="<?php echo (float) $txn['amount']; ?>"
               value="<?php echo number_format((float) $txn['amount'], 2, '.', ''); ?>"
               style="width: 100px;">
        <button type="submit" class="btn btn-danger btn-sm"
                onclick="return confirm('Issue this refund?');">
            <span class="fas fa-money-bill-wave"></span> Refund
        </button>
    </form>
</div>
<?php endif; ?>

<!-- Related International Call Charges -->
<?php if (!empty($related_charges)): ?>
<h4 style="margin-top: 30px;">International Call Charges Included</h4>
<table class="table table-striped" style="font-size: 0.9em;">
    <thead>
        <tr>
            <th>Date</th>
            <th>Destination</th>
            <th>Country</th>
            <th>Duration</th>
            <th style="text-align: right;">Rate/Min</th>
            <th style="text-align: right;">Charge</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($related_charges as $cc): ?>
        <tr>
            <td><?php echo htmlspecialchars(substr($cc['call_date'] ?? '', 0, 16)); ?></td>
            <td><?php echo htmlspecialchars($cc['destination_number'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($cc['country_name'] ?? ''); ?></td>
            <td><?php
                $sec = (int) ($cc['billsec'] ?? 0);
                echo sprintf('%dm %02ds', floor($sec / 60), $sec % 60);
            ?></td>
            <td style="text-align: right;">$<?php echo number_format((float) ($cc['rate_per_minute'] ?? 0), 4); ?></td>
            <td style="text-align: right;">$<?php echo number_format((float) ($cc['charge_amount_rounded'] ?? 0), 2); ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?php
require_once 'resources/footer.php';
