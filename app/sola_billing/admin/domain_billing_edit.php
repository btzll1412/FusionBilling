<?php
/**
 * domain_billing_edit.php — Edit a domain's billing configuration
 *
 * Features:
 * - Enable/disable billing
 * - Set rate per extension
 * - Enable/disable international billing
 * - Add card via Sola iFields (PCI-compliant)
 * - View/manage saved cards
 * - Test charge ($0.01 authorization)
 * - View billing history
 */

// FusionPBX bootstrap
require_once dirname(__DIR__, 3) . '/resources/require.php';
require_once 'resources/check_auth.php';

// Check permissions
if (!permission_exists('sola_billing_domain')) {
    echo "access denied";
    exit;
}

// Load classes
require_once dirname(__DIR__) . '/resources/classes/BillingDatabase.php';
require_once dirname(__DIR__) . '/resources/classes/SolaGateway.php';
$billing_db = new BillingDatabase();

// Validate domain_uuid
$domain_uuid = $_GET['domain_uuid'] ?? $_POST['domain_uuid'] ?? '';
if (empty($domain_uuid) || !is_string($domain_uuid)) {
    echo "Invalid domain UUID.";
    exit;
}

// Load settings for API key
$settings = $billing_db->getAllSettings();
$sandbox = ($settings['sandbox_mode'] ?? 'false') === 'true';
$api_key = $sandbox ? ($settings['api_key_sandbox'] ?? '') : ($settings['api_key'] ?? '');

// Get domain info
$db = new database;
$db->execute("SELECT domain_name FROM v_domains WHERE domain_uuid = :uuid", [':uuid' => $domain_uuid]);
$domain_rows = $db->result();
if (empty($domain_rows)) {
    echo "Domain not found.";
    exit;
}
$domain_name = $domain_rows[0]['domain_name'];

// Get current billing config
$billing = $billing_db->getDomainBilling($domain_uuid);
$payment_methods = $billing_db->getPaymentMethods($domain_uuid);
$extension_count = $billing_db->countActiveExtensions($domain_uuid);

$message = '';
$error = '';

// ─── Handle form submissions ─────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['token']) || !validate_token($_POST['token'])) {
        $error = 'Invalid token. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';

        switch ($action) {
            // Save billing settings
            case 'save_billing':
                $data = [
                    'billing_enabled'      => isset($_POST['billing_enabled']) ? 'true' : 'false',
                    'rate_per_extension'   => (float) ($_POST['rate_per_extension'] ?? 0),
                    'intl_billing_enabled' => isset($_POST['intl_billing_enabled']) ? 'true' : 'false',
                ];
                $billing_db->saveDomainBilling($domain_uuid, $data);
                $billing = $billing_db->getDomainBilling($domain_uuid);
                $message = 'Billing settings saved.';

                $billing_db->writeAuditLog(
                    $domain_uuid,
                    $_SESSION['username'] ?? 'admin',
                    'billing_settings_updated',
                    $data,
                    $_SERVER['REMOTE_ADDR'] ?? null
                );
                break;

            // Save a new card via iFields SUT
            case 'save_card':
                $sut = $_POST['sut'] ?? '';
                $exp = $_POST['card_exp'] ?? '';
                $name = $_POST['cardholder_name'] ?? '';

                if (empty($sut) || empty($exp) || empty($name)) {
                    $error = 'Card details are incomplete.';
                } elseif (empty($api_key)) {
                    $error = 'Sola API key is not configured. Go to Settings first.';
                } else {
                    $gateway = new SolaGateway($api_key, $sandbox);
                    $result = $gateway->saveCard($sut, $exp, $name);

                    if ($gateway->isApproved($result) || !empty($gateway->getToken($result))) {
                        $token = $gateway->getToken($result);
                        $masked = $gateway->getMaskedCard($result) ?? 'xxxx';
                        $card_type = $gateway->getCardType($result) ?? '';

                        $method_uuid = $billing_db->savePaymentMethod($domain_uuid, [
                            'sola_token'      => $token,
                            'token_type'      => 'cc',
                            'card_type'       => $card_type,
                            'masked_card'     => $masked,
                            'exp_date'        => $exp,
                            'cardholder_name' => $name,
                            'is_default'      => empty($payment_methods) ? 'true' : 'false',
                            'created_by'      => $_SESSION['username'] ?? 'admin',
                        ]);

                        // Set as default if first card
                        if (empty($payment_methods)) {
                            $billing_db->setDefaultPaymentMethod($domain_uuid, $method_uuid);
                        }

                        $message = 'Card saved successfully.';
                        $payment_methods = $billing_db->getPaymentMethods($domain_uuid);

                        $billing_db->writeAuditLog(
                            $domain_uuid,
                            $_SESSION['username'] ?? 'admin',
                            'card_saved',
                            ['masked_card' => $masked, 'card_type' => $card_type],
                            $_SERVER['REMOTE_ADDR'] ?? null
                        );
                    } else {
                        $error = 'Failed to save card: ' . $gateway->getErrorMessage($result);
                    }
                }
                break;

            // Set a card as default
            case 'set_default_card':
                $method_uuid = $_POST['method_uuid'] ?? '';
                if (!empty($method_uuid)) {
                    $billing_db->setDefaultPaymentMethod($domain_uuid, $method_uuid);
                    $payment_methods = $billing_db->getPaymentMethods($domain_uuid);
                    $billing = $billing_db->getDomainBilling($domain_uuid);
                    $message = 'Default payment method updated.';
                }
                break;

            // Delete a card
            case 'delete_card':
                $method_uuid = $_POST['method_uuid'] ?? '';
                if (!empty($method_uuid)) {
                    $billing_db->deletePaymentMethod($method_uuid);
                    $payment_methods = $billing_db->getPaymentMethods($domain_uuid);
                    $message = 'Card removed.';

                    $billing_db->writeAuditLog(
                        $domain_uuid,
                        $_SESSION['username'] ?? 'admin',
                        'card_deleted',
                        ['method_uuid' => $method_uuid],
                        $_SERVER['REMOTE_ADDR'] ?? null
                    );
                }
                break;

            // Test charge ($0.01 auth)
            case 'test_charge':
                if (empty($api_key)) {
                    $error = 'Sola API key is not configured.';
                } else {
                    $default_method = $billing_db->getDefaultPaymentMethod($domain_uuid);
                    if (!$default_method) {
                        $error = 'No payment method on file for this domain.';
                    } else {
                        $gateway = new SolaGateway($api_key, $sandbox);
                        $result = $gateway->authorize(
                            $default_method['sola_token'],
                            0.01,
                            'TEST-' . strtoupper(substr($domain_name, 0, 8)),
                            $_SERVER['REMOTE_ADDR'] ?? ''
                        );

                        if ($gateway->isApproved($result)) {
                            $message = 'Test authorization successful! Card is valid. Auth code: ' .
                                       ($gateway->getAuthCode($result) ?? 'N/A');
                            // Void the test auth immediately
                            $ref = $gateway->getRefNum($result);
                            if ($ref) {
                                $gateway->void((string) $ref);
                            }
                        } else {
                            $error = 'Test authorization failed: ' . $gateway->getErrorMessage($result);
                        }

                        $billing_db->writeAuditLog(
                            $domain_uuid,
                            $_SESSION['username'] ?? 'admin',
                            'test_charge',
                            ['result' => $result['xResult'] ?? 'unknown'],
                            $_SERVER['REMOTE_ADDR'] ?? null
                        );
                    }
                }
                break;
        }
    }
}

// Reload billing after changes
$billing = $billing_db->getDomainBilling($domain_uuid);

// Get recent transactions for this domain
$recent_txns = $billing_db->getTransactions(['domain_uuid' => $domain_uuid], 10);

// Page title
$document['title'] = "Sola Billing — {$domain_name}";
require_once 'resources/header.php';

$billing_enabled = ($billing['billing_enabled'] ?? '') === 't' || ($billing['billing_enabled'] ?? '') === 'true';
$intl_enabled = ($billing['intl_billing_enabled'] ?? '') === 't' || ($billing['intl_billing_enabled'] ?? '') === 'true';
$restricted = ($billing['calls_restricted'] ?? '') === 't' || ($billing['calls_restricted'] ?? '') === 'true';
$rate = (float) ($billing['rate_per_extension'] ?? $settings['default_rate'] ?? 0);

?>

<div class="action_bar" id="action_bar">
    <div class="heading">
        <b>Sola Billing — <?php echo htmlspecialchars($domain_name); ?></b>
    </div>
    <div class="actions">
        <a href="domain_billing.php" class="btn btn-default btn-sm">
            <span class="fas fa-arrow-left"></span> Back
        </a>
    </div>
</div>

<?php if (!empty($message)): ?>
<div class="alert alert-success" style="margin: 10px 0;">
    <?php echo htmlspecialchars($message); ?>
</div>
<?php endif; ?>

<?php if (!empty($error)): ?>
<div class="alert alert-danger" style="margin: 10px 0;">
    <?php echo htmlspecialchars($error); ?>
</div>
<?php endif; ?>

<?php if ($restricted): ?>
<div class="alert alert-danger" style="margin: 10px 0; font-weight: bold;">
    ⚠ Outbound calls are currently RESTRICTED for this domain due to a failed billing charge.
</div>
<?php endif; ?>

<!-- ─── BILLING SETTINGS ─────────────────────────────────────────── -->
<h4>Billing Configuration</h4>
<form method="post">
    <input type="hidden" name="action" value="save_billing">
    <input type="hidden" name="domain_uuid" value="<?php echo htmlspecialchars($domain_uuid); ?>">
    <input type="hidden" name="token" value="<?php echo create_token(); ?>">

    <table class="table" style="width: auto;">
        <tr>
            <td class="vncell" style="width: 220px;">Domain</td>
            <td class="vtable">
                <strong><?php echo htmlspecialchars($domain_name); ?></strong>
                &nbsp;—&nbsp;
                <?php echo $extension_count; ?> active extension<?php echo $extension_count !== 1 ? 's' : ''; ?>
            </td>
        </tr>
        <tr>
            <td class="vncell">Enable Billing</td>
            <td class="vtable">
                <label>
                    <input type="checkbox" name="billing_enabled" value="true"
                           <?php echo $billing_enabled ? 'checked' : ''; ?>>
                    Charge this domain monthly (per-extension × rate)
                </label>
            </td>
        </tr>
        <tr>
            <td class="vncell">Rate Per Extension</td>
            <td class="vtable">
                $<input type="number" name="rate_per_extension" class="formfld"
                       step="0.01" min="0" style="width: 120px;"
                       value="<?php echo number_format($rate, 2, '.', ''); ?>">
                /month
                <br><span class="description">
                    Estimated monthly: <?php echo $extension_count; ?> ext × $<?php echo number_format($rate, 2); ?>
                    = <strong>$<?php echo number_format($extension_count * $rate, 2); ?></strong>
                </span>
            </td>
        </tr>
        <tr>
            <td class="vncell">International Billing</td>
            <td class="vtable">
                <label>
                    <input type="checkbox" name="intl_billing_enabled" value="true"
                           <?php echo $intl_enabled ? 'checked' : ''; ?>>
                    Track and bill international calls for this domain
                </label>
            </td>
        </tr>
        <tr>
            <td></td>
            <td>
                <button type="submit" class="btn btn-primary btn-sm">
                    <span class="fas fa-save"></span> Save Settings
                </button>
            </td>
        </tr>
    </table>
</form>

<!-- ─── PAYMENT METHODS ──────────────────────────────────────────── -->
<h4 style="margin-top: 30px;">Payment Methods</h4>

<?php if (!empty($payment_methods)): ?>
<table class="table table-striped" style="width: auto;">
    <thead>
        <tr>
            <th>Card</th>
            <th>Type</th>
            <th>Cardholder</th>
            <th>Expires</th>
            <th>Default</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($payment_methods as $pm): ?>
        <tr>
            <td><?php echo htmlspecialchars($pm['masked_card'] ?? '****'); ?></td>
            <td><?php echo htmlspecialchars($pm['card_type'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($pm['cardholder_name'] ?? ''); ?></td>
            <td><?php
                $exp = $pm['exp_date'] ?? '';
                echo !empty($exp) ? substr($exp, 0, 2) . '/' . substr($exp, 2) : '';
            ?></td>
            <td>
                <?php if (($pm['is_default'] ?? '') === 't' || ($pm['is_default'] ?? '') === 'true'): ?>
                    <span style="color: #5cb85c; font-weight: bold;">Default</span>
                <?php else: ?>
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="action" value="set_default_card">
                        <input type="hidden" name="domain_uuid" value="<?php echo htmlspecialchars($domain_uuid); ?>">
                        <input type="hidden" name="method_uuid" value="<?php echo htmlspecialchars($pm['method_uuid']); ?>">
                        <input type="hidden" name="token" value="<?php echo create_token(); ?>">
                        <button type="submit" class="btn btn-default btn-xs">Set Default</button>
                    </form>
                <?php endif; ?>
            </td>
            <td>
                <form method="post" style="display: inline;"
                      onsubmit="return confirm('Remove this card?');">
                    <input type="hidden" name="action" value="delete_card">
                    <input type="hidden" name="domain_uuid" value="<?php echo htmlspecialchars($domain_uuid); ?>">
                    <input type="hidden" name="method_uuid" value="<?php echo htmlspecialchars($pm['method_uuid']); ?>">
                    <input type="hidden" name="token" value="<?php echo create_token(); ?>">
                    <button type="submit" class="btn btn-danger btn-xs">
                        <span class="fas fa-trash"></span> Remove
                    </button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<form method="post" style="display: inline;">
    <input type="hidden" name="action" value="test_charge">
    <input type="hidden" name="domain_uuid" value="<?php echo htmlspecialchars($domain_uuid); ?>">
    <input type="hidden" name="token" value="<?php echo create_token(); ?>">
    <button type="submit" class="btn btn-info btn-sm"
            onclick="return confirm('This will authorize $0.01 on the default card to verify it is working. Proceed?');">
        <span class="fas fa-credit-card"></span> Test Charge ($0.01)
    </button>
</form>
<?php else: ?>
<p style="color: #999;">No payment methods on file. Add a card below.</p>
<?php endif; ?>

<!-- ─── ADD CARD (iFields) ───────────────────────────────────────── -->
<h4 style="margin-top: 30px;">Add New Card</h4>
<div id="card_form_container" style="max-width: 500px;">
    <form id="card_form" method="post">
        <input type="hidden" name="action" value="save_card">
        <input type="hidden" name="domain_uuid" value="<?php echo htmlspecialchars($domain_uuid); ?>">
        <input type="hidden" name="token" value="<?php echo create_token(); ?>">
        <input type="hidden" name="sut" id="sut_field" value="">

        <table class="table" style="width: auto;">
            <tr>
                <td class="vncell" style="width: 160px;">Cardholder Name</td>
                <td class="vtable">
                    <input type="text" name="cardholder_name" id="cardholder_name"
                           class="formfld" style="width: 300px;" required>
                </td>
            </tr>
            <tr>
                <td class="vncell">Card Number</td>
                <td class="vtable">
                    <div id="ifields-card-number" style="width: 300px; height: 40px; border: 1px solid #ccc;"></div>
                </td>
            </tr>
            <tr>
                <td class="vncell">CVV</td>
                <td class="vtable">
                    <div id="ifields-cvv" style="width: 120px; height: 40px; border: 1px solid #ccc;"></div>
                </td>
            </tr>
            <tr>
                <td class="vncell">Expiration (MMYY)</td>
                <td class="vtable">
                    <input type="text" name="card_exp" id="card_exp" class="formfld"
                           style="width: 80px;" maxlength="4" placeholder="MMYY" required
                           pattern="[0-9]{4}">
                </td>
            </tr>
            <tr>
                <td></td>
                <td>
                    <button type="button" id="save_card_btn" class="btn btn-primary btn-sm"
                            onclick="submitCardForm();">
                        <span class="fas fa-plus"></span> Save Card
                    </button>
                    <span id="card_status" style="margin-left: 10px;"></span>
                </td>
            </tr>
        </table>
    </form>
</div>

<!-- Sola iFields JavaScript -->
<script src="https://cdn.cardknox.com/ifields/2.15.2302.0801/ifields.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize iFields
    if (typeof setAccount === 'function') {
        setAccount("<?php echo htmlspecialchars($api_key); ?>");
        setIfieldStyle('card-number', 'width: 100%; height: 100%; font-size: 14px; padding: 5px; border: none;');
        setIfieldStyle('cvv', 'width: 100%; height: 100%; font-size: 14px; padding: 5px; border: none;');

        enableAutoFormatting('-');
        addIfieldCallback('token', function(data) {
            if (data.xToken) {
                document.getElementById('sut_field').value = data.xToken;
                document.getElementById('card_form').submit();
            } else {
                document.getElementById('card_status').innerText = 'Error: ' + (data.xTokenError || 'Could not get token');
                document.getElementById('save_card_btn').disabled = false;
            }
        });
    }
});

function submitCardForm() {
    var name = document.getElementById('cardholder_name').value;
    var exp = document.getElementById('card_exp').value;

    if (!name || !exp || exp.length !== 4) {
        alert('Please fill in all card fields.');
        return;
    }

    document.getElementById('save_card_btn').disabled = true;
    document.getElementById('card_status').innerText = 'Processing...';

    // Request single-use token from iFields
    if (typeof getTokens === 'function') {
        getTokens(function() {
            // Callback handled by addIfieldCallback above
        }, function(error) {
            document.getElementById('card_status').innerText = 'Error: ' + error;
            document.getElementById('save_card_btn').disabled = false;
        }, 30000);
    } else {
        document.getElementById('card_status').innerText = 'iFields not loaded. Check API key.';
        document.getElementById('save_card_btn').disabled = false;
    }
}
</script>

<!-- ─── RECENT TRANSACTIONS ──────────────────────────────────────── -->
<h4 style="margin-top: 30px;">Recent Billing History</h4>
<?php if (!empty($recent_txns)): ?>
<table class="table table-striped" style="width: auto;">
    <thead>
        <tr>
            <th>Date</th>
            <th>Type</th>
            <th style="text-align: right;">Amount</th>
            <th>Result</th>
            <th>Invoice</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($recent_txns as $txn): ?>
        <tr>
            <td><?php echo htmlspecialchars(substr($txn['created_at'] ?? '', 0, 16)); ?></td>
            <td><?php echo htmlspecialchars($txn['charge_type'] ?? ''); ?></td>
            <td style="text-align: right;">$<?php echo number_format((float) ($txn['amount'] ?? 0), 2); ?></td>
            <td>
                <?php
                $r = $txn['result'] ?? '';
                if ($r === 'A') echo '<span style="color: #5cb85c;">Approved</span>';
                elseif ($r === 'D') echo '<span style="color: #d9534f;">Declined</span>';
                elseif ($r === 'E') echo '<span style="color: #f0ad4e;">Error</span>';
                ?>
            </td>
            <td><?php echo htmlspecialchars($txn['x_invoice'] ?? ''); ?></td>
            <td>
                <a href="transaction_detail.php?transaction_uuid=<?php echo htmlspecialchars($txn['transaction_uuid']); ?>"
                   class="btn btn-default btn-xs">View</a>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php else: ?>
<p style="color: #999;">No billing history yet.</p>
<?php endif; ?>

<?php
require_once 'resources/footer.php';
