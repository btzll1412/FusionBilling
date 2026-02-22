<?php
/**
 * failed_charges.php — Failed charges queue + manual retry.
 *
 * Shows all domains with failed charges, sorted by most recent failure.
 * Actions: Retry Now, Waive Charge, Lift Restriction, Update Card.
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
$classes_dir = dirname(__DIR__) . '/resources/classes/';
require_once $classes_dir . 'BillingDatabase.php';
require_once $classes_dir . 'SolaGateway.php';
require_once $classes_dir . 'FailureHandler.php';
require_once $classes_dir . 'BillingEngine.php';

$billing_db = new BillingDatabase();
$message = '';
$error = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['token']) && validate_token($_POST['token'])) {
    $action = $_POST['action'] ?? '';
    $domain_uuid = $_POST['domain_uuid'] ?? '';

    if (empty($domain_uuid)) {
        $error = 'No domain specified.';
    } else {
        $settings = $billing_db->getAllSettings();
        $sandbox = ($settings['sandbox_mode'] ?? 'false') === 'true';
        $api_key = $sandbox ? ($settings['api_key_sandbox'] ?? '') : ($settings['api_key'] ?? '');

        switch ($action) {
            case 'retry_now':
                if (empty($api_key)) {
                    $error = 'No API key configured.';
                } else {
                    $gateway = new SolaGateway($api_key, $sandbox);
                    $failure_handler = new FailureHandler($billing_db);
                    $engine = new BillingEngine($billing_db, $gateway, $failure_handler);

                    $result = $engine->retryFailedDomain($domain_uuid);
                    if (($result['status'] ?? '') === 'approved') {
                        $message = "Retry successful! Charged \$" . number_format($result['amount'] ?? 0, 2) .
                                   ". Restriction lifted.";
                    } elseif (($result['status'] ?? '') === 'declined') {
                        $error = "Retry failed: " . ($result['error'] ?? 'Unknown error');
                    } else {
                        $error = $result['message'] ?? 'Retry could not be processed.';
                    }
                }
                break;

            case 'lift_restriction':
                $reason = $_POST['reason'] ?? '';
                if (empty($reason)) {
                    $error = 'A reason is required to manually lift a restriction.';
                } else {
                    $failure_handler = new FailureHandler($billing_db);
                    $failure_handler->liftRestriction(
                        $domain_uuid,
                        $_SESSION['username'] ?? 'admin',
                        $reason
                    );
                    // Also reset retry state
                    $billing_db->saveDomainBilling($domain_uuid, [
                        'retry_count'  => 0,
                        'next_retry_at' => null,
                    ]);
                    $message = "Restriction lifted for domain. Reason logged: " . htmlspecialchars($reason);
                }
                break;

            case 'waive_charge':
                // Mark all pending call charges as waived
                $pg = new database;
                $pg->execute(
                    "UPDATE v_sola_call_charges SET status = 'waived'
                     WHERE domain_uuid = :uuid AND status = 'pending'",
                    [':uuid' => $domain_uuid]
                );
                // Reset billing state
                $billing_db->saveDomainBilling($domain_uuid, [
                    'last_charge_result' => null,
                    'retry_count'        => 0,
                    'next_retry_at'      => null,
                ]);
                $message = 'Charges waived. Pending call charges marked as waived.';

                $billing_db->writeAuditLog(
                    $domain_uuid,
                    $_SESSION['username'] ?? 'admin',
                    'charges_waived',
                    [],
                    $_SERVER['REMOTE_ADDR'] ?? null
                );
                break;
        }
    }
}

// Get failed domains
$failed_domains = $billing_db->getFailedDomains();

// Page title
$document['title'] = 'Sola Billing — Failed Charges';
require_once 'resources/header.php';

?>

<div class="action_bar" id="action_bar">
    <div class="heading">
        <b>Sola Billing — Failed Charges</b>
        <?php if (!empty($failed_domains)): ?>
        <span style="color: #d9534f; font-size: 0.9em; margin-left: 10px;">
            <?php echo count($failed_domains); ?> domain<?php echo count($failed_domains) !== 1 ? 's' : ''; ?> with issues
        </span>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($message)): ?>
<div class="alert alert-success" style="margin: 10px 0;"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
<div class="alert alert-danger" style="margin: 10px 0;"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<?php if (empty($failed_domains)): ?>
<div style="text-align: center; padding: 40px; color: #5cb85c;">
    <span class="fas fa-check-circle" style="font-size: 48px;"></span>
    <p style="font-size: 16px; margin-top: 10px;">No failed charges. All domains are current.</p>
</div>
<?php else: ?>

<table class="table table-striped">
    <thead>
        <tr>
            <th>Domain</th>
            <th style="text-align: right;">Amount</th>
            <th>Failure Date</th>
            <th>Failure Reason</th>
            <th style="text-align: center;">Retries</th>
            <th>Next Retry</th>
            <th>Restriction</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($failed_domains as $fd): ?>
        <?php
            $d_uuid = $fd['domain_uuid'];
            $restricted = ($fd['calls_restricted'] ?? '') === 't' || ($fd['calls_restricted'] ?? '') === 'true';
            $retry_count = (int) ($fd['retry_count'] ?? 0);
            $max_retries = (int) ($billing_db->getSetting('max_retry_attempts') ?? 3);
        ?>
        <tr>
            <td>
                <a href="domain_billing_edit.php?domain_uuid=<?php echo htmlspecialchars($d_uuid); ?>">
                    <?php echo htmlspecialchars($fd['domain_name'] ?? ''); ?>
                </a>
            </td>
            <td style="text-align: right;">
                $<?php echo number_format((float) ($fd['last_amount'] ?? 0), 2); ?>
            </td>
            <td><?php echo htmlspecialchars($fd['last_charge_date'] ?? ''); ?></td>
            <td style="color: #d9534f;">
                <?php echo htmlspecialchars($fd['last_error'] ?? 'Unknown'); ?>
            </td>
            <td style="text-align: center;">
                <?php echo $retry_count; ?>/<?php echo $max_retries; ?>
                <?php if ($retry_count > $max_retries): ?>
                    <br><span style="color: #d9534f; font-size: 0.8em;">EXHAUSTED</span>
                <?php endif; ?>
            </td>
            <td>
                <?php
                    $next = $fd['next_retry_at'] ?? '';
                    echo !empty($next) ? htmlspecialchars(substr($next, 0, 16)) : '—';
                ?>
            </td>
            <td>
                <?php if ($restricted): ?>
                    <span style="color: #d9534f; font-weight: bold;">BLOCKED</span>
                    <br><small>Since <?php echo htmlspecialchars(substr($fd['restriction_started_at'] ?? '', 0, 10)); ?></small>
                <?php else: ?>
                    <span style="color: #5cb85c;">OK</span>
                <?php endif; ?>
            </td>
            <td>
                <!-- Retry Now -->
                <form method="post" style="display: inline; margin-right: 5px;">
                    <input type="hidden" name="action" value="retry_now">
                    <input type="hidden" name="domain_uuid" value="<?php echo htmlspecialchars($d_uuid); ?>">
                    <input type="hidden" name="token" value="<?php echo create_token(); ?>">
                    <button type="submit" class="btn btn-primary btn-xs"
                            onclick="return confirm('Retry charge for this domain now?');">
                        <span class="fas fa-redo"></span> Retry
                    </button>
                </form>

                <!-- Waive -->
                <form method="post" style="display: inline; margin-right: 5px;">
                    <input type="hidden" name="action" value="waive_charge">
                    <input type="hidden" name="domain_uuid" value="<?php echo htmlspecialchars($d_uuid); ?>">
                    <input type="hidden" name="token" value="<?php echo create_token(); ?>">
                    <button type="submit" class="btn btn-warning btn-xs"
                            onclick="return confirm('Waive all pending charges for this domain? This cannot be undone.');">
                        <span class="fas fa-ban"></span> Waive
                    </button>
                </form>

                <?php if ($restricted): ?>
                <!-- Lift Restriction -->
                <button type="button" class="btn btn-danger btn-xs"
                        onclick="liftRestriction('<?php echo htmlspecialchars($d_uuid); ?>');">
                    <span class="fas fa-unlock"></span> Lift Restriction
                </button>
                <?php endif; ?>

                <!-- Update Card -->
                <a href="domain_billing_edit.php?domain_uuid=<?php echo htmlspecialchars($d_uuid); ?>"
                   class="btn btn-default btn-xs">
                    <span class="fas fa-credit-card"></span> Card
                </a>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<!-- Lift Restriction Modal (simple prompt) -->
<script>
function liftRestriction(domain_uuid) {
    var reason = prompt('Enter a reason for lifting the restriction (required):');
    if (reason === null || reason.trim() === '') {
        alert('A reason is required to lift a restriction.');
        return;
    }

    var form = document.createElement('form');
    form.method = 'POST';

    var fields = {
        'action': 'lift_restriction',
        'domain_uuid': domain_uuid,
        'reason': reason.trim(),
        'token': '<?php echo create_token(); ?>'
    };

    for (var key in fields) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = key;
        input.value = fields[key];
        form.appendChild(input);
    }

    document.body.appendChild(form);
    form.submit();
}
</script>

<?php endif; ?>

<?php
require_once 'resources/footer.php';
