<?php
/**
 * transactions.php — All transactions list.
 *
 * Filterable by: domain, date range, charge type, result.
 * Columns: Date, Domain, Type, Amount, Card Used, Result, Invoice #, Actions.
 */

// FusionPBX bootstrap
require_once dirname(__DIR__, 3) . '/resources/require.php';
require_once 'resources/check_auth.php';
require_once 'resources/paging.php';

if (!permission_exists('sola_billing_transactions')) {
    echo "access denied";
    exit;
}

require_once dirname(__DIR__) . '/resources/classes/BillingDatabase.php';
$billing_db = new BillingDatabase();

// Filters
$filters = [];
if (!empty($_GET['domain_uuid'])) $filters['domain_uuid'] = $_GET['domain_uuid'];
if (!empty($_GET['charge_type'])) $filters['charge_type'] = $_GET['charge_type'];
if (!empty($_GET['result']))      $filters['result']      = $_GET['result'];
if (!empty($_GET['date_from']))   $filters['date_from']   = $_GET['date_from'];
if (!empty($_GET['date_to']))     $filters['date_to']     = $_GET['date_to'];

// Pagination
$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 50;
$offset = ($page - 1) * $per_page;

$total = $billing_db->countTransactions($filters);
$transactions = $billing_db->getTransactions($filters, $per_page, $offset);
$total_pages = max(1, ceil($total / $per_page));

// Get domain list for filter dropdown
$db = new database;
$db->execute("SELECT domain_uuid, domain_name FROM v_domains ORDER BY domain_name");
$all_domains = $db->result() ?: [];

$document['title'] = 'Sola Billing — Transactions';
require_once 'resources/header.php';

?>

<div class="action_bar" id="action_bar">
    <div class="heading">
        <b>Sola Billing — Transactions</b>
        <span style="color: #999; font-size: 0.9em; margin-left: 10px;">
            <?php echo number_format($total); ?> transaction<?php echo $total !== 1 ? 's' : ''; ?>
        </span>
    </div>
</div>

<!-- Filters -->
<form method="get" style="margin: 10px 0; display: flex; gap: 10px; flex-wrap: wrap; align-items: end;">
    <div>
        <label style="font-size: 11px;">Domain</label><br>
        <select name="domain_uuid" class="formfld" style="width: 200px;">
            <option value="">All Domains</option>
            <?php foreach ($all_domains as $d): ?>
            <option value="<?php echo htmlspecialchars($d['domain_uuid']); ?>"
                    <?php echo ($filters['domain_uuid'] ?? '') === $d['domain_uuid'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($d['domain_name']); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label style="font-size: 11px;">Type</label><br>
        <select name="charge_type" class="formfld">
            <option value="">All Types</option>
            <option value="monthly_invoice" <?php echo ($filters['charge_type'] ?? '') === 'monthly_invoice' ? 'selected' : ''; ?>>Monthly Invoice</option>
            <option value="manual" <?php echo ($filters['charge_type'] ?? '') === 'manual' ? 'selected' : ''; ?>>Manual</option>
            <option value="refund" <?php echo ($filters['charge_type'] ?? '') === 'refund' ? 'selected' : ''; ?>>Refund</option>
            <option value="void" <?php echo ($filters['charge_type'] ?? '') === 'void' ? 'selected' : ''; ?>>Void</option>
        </select>
    </div>
    <div>
        <label style="font-size: 11px;">Result</label><br>
        <select name="result" class="formfld">
            <option value="">All Results</option>
            <option value="A" <?php echo ($filters['result'] ?? '') === 'A' ? 'selected' : ''; ?>>Approved</option>
            <option value="D" <?php echo ($filters['result'] ?? '') === 'D' ? 'selected' : ''; ?>>Declined</option>
            <option value="E" <?php echo ($filters['result'] ?? '') === 'E' ? 'selected' : ''; ?>>Error</option>
        </select>
    </div>
    <div>
        <label style="font-size: 11px;">From</label><br>
        <input type="date" name="date_from" class="formfld" style="width: 140px;"
               value="<?php echo htmlspecialchars($filters['date_from'] ?? ''); ?>">
    </div>
    <div>
        <label style="font-size: 11px;">To</label><br>
        <input type="date" name="date_to" class="formfld" style="width: 140px;"
               value="<?php echo htmlspecialchars($filters['date_to'] ?? ''); ?>">
    </div>
    <div>
        <button type="submit" class="btn btn-default btn-sm"><span class="fas fa-filter"></span> Filter</button>
        <a href="transactions.php" class="btn btn-default btn-sm">Clear</a>
    </div>
</form>

<!-- Transaction Table -->
<table class="table table-striped table-hover">
    <thead>
        <tr>
            <th>Date</th>
            <th>Domain</th>
            <th>Type</th>
            <th style="text-align: right;">Amount</th>
            <th>Card</th>
            <th>Result</th>
            <th>Invoice #</th>
            <th style="text-align: center;">Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($transactions)): ?>
        <tr>
            <td colspan="8" style="text-align: center; padding: 20px; color: #999;">
                No transactions found.
            </td>
        </tr>
        <?php else: ?>
        <?php foreach ($transactions as $txn): ?>
        <?php
            $result = $txn['result'] ?? '';
            $result_class = $result === 'A' ? 'color: #5cb85c;' :
                           ($result === 'D' ? 'color: #d9534f;' : 'color: #f0ad4e;');
            $result_text = $result === 'A' ? 'Approved' :
                          ($result === 'D' ? 'Declined' : ($result === 'E' ? 'Error' : ''));
        ?>
        <tr>
            <td><?php echo htmlspecialchars(substr($txn['created_at'] ?? '', 0, 16)); ?></td>
            <td><?php echo htmlspecialchars($txn['domain_name'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars(str_replace('_', ' ', $txn['charge_type'] ?? '')); ?></td>
            <td style="text-align: right;">$<?php echo number_format((float) ($txn['amount'] ?? 0), 2); ?></td>
            <td>
                <?php if (!empty($txn['card_type'])): ?>
                    <?php echo htmlspecialchars($txn['card_type']); ?>
                <?php endif; ?>
                <?php echo htmlspecialchars($txn['masked_card'] ?? ''); ?>
            </td>
            <td style="<?php echo $result_class; ?>"><?php echo $result_text; ?></td>
            <td><?php echo htmlspecialchars($txn['x_invoice'] ?? ''); ?></td>
            <td style="text-align: center;">
                <a href="transaction_detail.php?transaction_uuid=<?php echo htmlspecialchars($txn['transaction_uuid']); ?>"
                   class="btn btn-default btn-xs">
                    <span class="fas fa-eye"></span> View
                </a>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

<!-- Pagination -->
<?php if ($total_pages > 1): ?>
<div style="text-align: center; margin: 20px 0;">
    <?php
        $query = $_GET;
        for ($p = 1; $p <= $total_pages; $p++) {
            $query['page'] = $p;
            $qs = http_build_query($query);
            if ($p === $page) {
                echo "<strong>[{$p}]</strong> ";
            } else {
                echo "<a href=\"?{$qs}\">{$p}</a> ";
            }
        }
    ?>
</div>
<?php endif; ?>

<?php
require_once 'resources/footer.php';
