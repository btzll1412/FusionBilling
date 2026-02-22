<?php
/**
 * domain_billing.php — Domain billing overview table
 *
 * Lists all FusionPBX domains with their billing status, extension count,
 * rate, card on file, and last charge result.
 */

// FusionPBX bootstrap
require_once dirname(__DIR__, 3) . '/resources/require.php';
require_once 'resources/check_auth.php';
require_once 'resources/paging.php';

// Check permissions
if (!permission_exists('sola_billing_domain')) {
    echo "access denied";
    exit;
}

// Load classes
require_once dirname(__DIR__) . '/resources/classes/BillingDatabase.php';
$billing_db = new BillingDatabase();

// Get all domains with billing info
$domains = $billing_db->getAllDomainBilling();

// Page title
$document['title'] = 'Sola Billing — Domain Billing';

// Include header
require_once 'resources/header.php';

?>

<div class="action_bar" id="action_bar">
    <div class="heading">
        <b>Sola Billing — Domain Billing</b>
    </div>
    <div class="actions">
    </div>
</div>

<table class="table table-striped table-hover" id="domain_billing_table">
    <thead>
        <tr>
            <th>Domain Name</th>
            <th style="text-align: center;">Extensions</th>
            <th>Billing</th>
            <th>Intl Billing</th>
            <th style="text-align: right;">Rate/Ext</th>
            <th style="text-align: right;">Monthly Est.</th>
            <th>Card on File</th>
            <th>Last Charge</th>
            <th>Result</th>
            <th>Status</th>
            <th style="text-align: center;">Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($domains)): ?>
        <tr>
            <td colspan="11" style="text-align: center; padding: 20px;">
                No domains found. Domains are managed in FusionPBX core.
            </td>
        </tr>
        <?php else: ?>
        <?php foreach ($domains as $domain): ?>
        <?php
            $billing_enabled = ($domain['billing_enabled'] ?? false) === true || ($domain['billing_enabled'] ?? '') === 't';
            $intl_enabled = ($domain['intl_billing_enabled'] ?? false) === true || ($domain['intl_billing_enabled'] ?? '') === 't';
            $restricted = ($domain['calls_restricted'] ?? false) === true || ($domain['calls_restricted'] ?? '') === 't';
            $ext_count = (int) ($domain['extension_count'] ?? 0);
            $rate = (float) ($domain['rate_per_extension'] ?? 0);
            $monthly_est = $ext_count * $rate;
            $last_result = $domain['last_charge_result'] ?? '';
            $last_date = $domain['last_charge_date'] ?? '';

            if ($restricted) {
                $status = '<span style="color: #d9534f; font-weight: bold;">Restricted</span>';
            } elseif ($billing_enabled) {
                $status = '<span style="color: #5cb85c;">Active</span>';
            } elseif (!empty($domain['billing_uuid'])) {
                $status = '<span style="color: #999;">Disabled</span>';
            } else {
                $status = '<span style="color: #999;">Not Configured</span>';
            }

            $result_display = '';
            if ($last_result === 'A') {
                $result_display = '<span style="color: #5cb85c;">Approved</span>';
            } elseif ($last_result === 'D') {
                $result_display = '<span style="color: #d9534f;">Declined</span>';
            } elseif ($last_result === 'E') {
                $result_display = '<span style="color: #f0ad4e;">Error</span>';
            }
        ?>
        <tr>
            <td><?php echo htmlspecialchars($domain['domain_name'] ?? ''); ?></td>
            <td style="text-align: center;"><?php echo $ext_count; ?></td>
            <td><?php echo $billing_enabled ? 'Enabled' : 'Disabled'; ?></td>
            <td><?php echo $intl_enabled ? 'Enabled' : 'Disabled'; ?></td>
            <td style="text-align: right;">$<?php echo number_format($rate, 2); ?></td>
            <td style="text-align: right;">$<?php echo number_format($monthly_est, 2); ?></td>
            <td><?php echo htmlspecialchars($domain['masked_card'] ?? 'No card'); ?></td>
            <td><?php echo !empty($last_date) ? htmlspecialchars($last_date) : '—'; ?></td>
            <td><?php echo $result_display ?: '—'; ?></td>
            <td><?php echo $status; ?></td>
            <td style="text-align: center;">
                <a href="domain_billing_edit.php?domain_uuid=<?php echo htmlspecialchars($domain['domain_uuid']); ?>"
                   class="btn btn-default btn-xs">
                    <span class="fas fa-pencil-alt"></span> Edit
                </a>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

<?php
require_once 'resources/footer.php';
