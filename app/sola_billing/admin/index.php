<?php
/**
 * index.php — Sola Billing Admin Dashboard
 *
 * Stats cards: Total Revenue This Month, Total Revenue YTD,
 * Active Domains Billing, Domains in Failed State, Pending Intl Charges.
 *
 * Tables: Recent Transactions (last 20) and Failed Charges Requiring Attention.
 */

// FusionPBX bootstrap
require_once dirname(__DIR__, 3) . '/resources/require.php';
require_once 'resources/check_auth.php';

if (!permission_exists('sola_billing_dashboard')) {
    echo "access denied";
    exit;
}

require_once dirname(__DIR__) . '/resources/classes/BillingDatabase.php';
$billing_db = new BillingDatabase();

// Dashboard data
$stats = $billing_db->getDashboardStats();
$recent_txns = $billing_db->getRecentTransactions(20);
$failed_domains = $billing_db->getFailedDomains();

$document['title'] = 'Sola Billing — Dashboard';
require_once 'resources/header.php';

?>

<div class="action_bar" id="action_bar">
    <div class="heading">
        <b>Sola Billing — Dashboard</b>
    </div>
</div>

<!-- Stats Cards -->
<div style="display: flex; gap: 15px; flex-wrap: wrap; margin: 15px 0;">
    <div style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 15px 25px; min-width: 180px;">
        <div style="font-size: 11px; color: #999; text-transform: uppercase;">Revenue This Month</div>
        <div style="font-size: 24px; font-weight: bold; color: #5cb85c;">
            $<?php echo number_format($stats['revenue_this_month'], 2); ?>
        </div>
    </div>
    <div style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 15px 25px; min-width: 180px;">
        <div style="font-size: 11px; color: #999; text-transform: uppercase;">Revenue YTD</div>
        <div style="font-size: 24px; font-weight: bold; color: #333;">
            $<?php echo number_format($stats['revenue_ytd'], 2); ?>
        </div>
    </div>
    <div style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 15px 25px; min-width: 180px;">
        <div style="font-size: 11px; color: #999; text-transform: uppercase;">Active Domains</div>
        <div style="font-size: 24px; font-weight: bold; color: #333;">
            <?php echo $stats['active_domains']; ?>
        </div>
    </div>
    <div style="background: #fff; border: 1px solid <?php echo $stats['failed_domains'] > 0 ? '#d9534f' : '#ddd'; ?>; border-radius: 4px; padding: 15px 25px; min-width: 180px;">
        <div style="font-size: 11px; color: #999; text-transform: uppercase;">Failed / Restricted</div>
        <div style="font-size: 24px; font-weight: bold; color: <?php echo $stats['failed_domains'] > 0 ? '#d9534f' : '#333'; ?>;">
            <?php echo $stats['failed_domains']; ?>
        </div>
    </div>
    <div style="background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 15px 25px; min-width: 180px;">
        <div style="font-size: 11px; color: #999; text-transform: uppercase;">Pending Intl Charges</div>
        <div style="font-size: 24px; font-weight: bold; color: #f0ad4e;">
            $<?php echo number_format($stats['pending_intl_charges'], 2); ?>
        </div>
    </div>
</div>

<!-- Failed Charges Requiring Attention -->
<?php if (!empty($failed_domains)): ?>
<h4 style="margin-top: 25px; color: #d9534f;">
    <span class="fas fa-exclamation-triangle"></span> Failed Charges Requiring Attention
</h4>
<table class="table table-striped" style="font-size: 0.9em;">
    <thead>
        <tr>
            <th>Domain</th>
            <th style="text-align: right;">Amount</th>
            <th>Last Failure</th>
            <th>Error</th>
            <th style="text-align: center;">Retries</th>
            <th>Status</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($failed_domains as $fd): ?>
        <?php $restricted = ($fd['calls_restricted'] ?? '') === 't' || ($fd['calls_restricted'] ?? '') === 'true'; ?>
        <tr>
            <td>
                <a href="domain_billing_edit.php?domain_uuid=<?php echo htmlspecialchars($fd['domain_uuid']); ?>">
                    <?php echo htmlspecialchars($fd['domain_name'] ?? ''); ?>
                </a>
            </td>
            <td style="text-align: right;">$<?php echo number_format((float) ($fd['last_amount'] ?? 0), 2); ?></td>
            <td><?php echo htmlspecialchars($fd['last_charge_date'] ?? ''); ?></td>
            <td style="color: #d9534f; font-size: 0.85em;"><?php echo htmlspecialchars($fd['last_error'] ?? ''); ?></td>
            <td style="text-align: center;"><?php echo (int) ($fd['retry_count'] ?? 0); ?></td>
            <td>
                <?php echo $restricted ? '<span style="color: #d9534f; font-weight: bold;">BLOCKED</span>' : 'Active'; ?>
            </td>
            <td>
                <a href="failed_charges.php" class="btn btn-default btn-xs">Manage</a>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<!-- Recent Transactions -->
<h4 style="margin-top: 25px;">Recent Transactions</h4>
<?php if (empty($recent_txns)): ?>
<p style="color: #999;">No transactions yet.</p>
<?php else: ?>
<table class="table table-striped table-hover" style="font-size: 0.9em;">
    <thead>
        <tr>
            <th>Date</th>
            <th>Domain</th>
            <th>Type</th>
            <th style="text-align: right;">Amount</th>
            <th>Card</th>
            <th>Result</th>
            <th>Invoice</th>
            <th style="text-align: center;">Detail</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($recent_txns as $txn): ?>
        <?php
            $r = $txn['result'] ?? '';
            $rc = $r === 'A' ? 'color: #5cb85c;' : ($r === 'D' ? 'color: #d9534f;' : 'color: #f0ad4e;');
            $rt = $r === 'A' ? 'Approved' : ($r === 'D' ? 'Declined' : ($r === 'E' ? 'Error' : ''));
        ?>
        <tr>
            <td><?php echo htmlspecialchars(substr($txn['created_at'] ?? '', 0, 16)); ?></td>
            <td><?php echo htmlspecialchars($txn['domain_name'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars(str_replace('_', ' ', $txn['charge_type'] ?? '')); ?></td>
            <td style="text-align: right;">$<?php echo number_format((float) ($txn['amount'] ?? 0), 2); ?></td>
            <td><?php echo htmlspecialchars(($txn['card_type'] ?? '') . ' ' . ($txn['masked_card'] ?? '')); ?></td>
            <td style="<?php echo $rc; ?>"><?php echo $rt; ?></td>
            <td><?php echo htmlspecialchars($txn['x_invoice'] ?? ''); ?></td>
            <td style="text-align: center;">
                <a href="transaction_detail.php?transaction_uuid=<?php echo htmlspecialchars($txn['transaction_uuid']); ?>"
                   class="btn btn-default btn-xs">View</a>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<div style="text-align: right; margin-top: 5px;">
    <a href="transactions.php">View all transactions &rarr;</a>
</div>
<?php endif; ?>

<?php
require_once 'resources/footer.php';
