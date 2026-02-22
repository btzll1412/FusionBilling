<?php
/**
 * call_charges.php — View accumulated international call charges by domain.
 *
 * Shows pending/billed international call charges.
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
if (!empty($_GET['status']))      $filters['status']      = $_GET['status'];

// Pagination
$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 50;
$offset = ($page - 1) * $per_page;

$charges = $billing_db->getCallCharges($filters, $per_page, $offset);

// Domain list for filter
$db = new database;
$db->execute("SELECT domain_uuid, domain_name FROM v_domains ORDER BY domain_name");
$all_domains = $db->result() ?: [];

$document['title'] = 'Sola Billing — International Call Charges';
require_once 'resources/header.php';

?>

<div class="action_bar" id="action_bar">
    <div class="heading">
        <b>Sola Billing — International Call Charges</b>
    </div>
</div>

<!-- Filters -->
<form method="get" style="margin: 10px 0; display: flex; gap: 10px; align-items: end;">
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
        <label style="font-size: 11px;">Status</label><br>
        <select name="status" class="formfld">
            <option value="">All</option>
            <option value="pending" <?php echo ($filters['status'] ?? '') === 'pending' ? 'selected' : ''; ?>>Pending</option>
            <option value="charged" <?php echo ($filters['status'] ?? '') === 'charged' ? 'selected' : ''; ?>>Charged</option>
            <option value="waived" <?php echo ($filters['status'] ?? '') === 'waived' ? 'selected' : ''; ?>>Waived</option>
        </select>
    </div>
    <div>
        <button type="submit" class="btn btn-default btn-sm"><span class="fas fa-filter"></span> Filter</button>
        <a href="call_charges.php" class="btn btn-default btn-sm">Clear</a>
    </div>
</form>

<table class="table table-striped table-hover" style="font-size: 0.9em;">
    <thead>
        <tr>
            <th>Call Date</th>
            <th>Domain</th>
            <th>Destination</th>
            <th>Country</th>
            <th>Prefix</th>
            <th>Duration</th>
            <th style="text-align: right;">Rate/Min</th>
            <th style="text-align: right;">Charge</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($charges)): ?>
        <tr>
            <td colspan="9" style="text-align: center; padding: 20px; color: #999;">
                No call charges found.
            </td>
        </tr>
        <?php else: ?>
        <?php foreach ($charges as $cc): ?>
        <?php
            $status = $cc['status'] ?? '';
            $status_style = $status === 'pending' ? 'color: #f0ad4e;' :
                           ($status === 'charged' ? 'color: #5cb85c;' : 'color: #999;');
        ?>
        <tr>
            <td><?php echo htmlspecialchars(substr($cc['call_date'] ?? '', 0, 16)); ?></td>
            <td><?php echo htmlspecialchars($cc['domain_name'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($cc['destination_number'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($cc['country_name'] ?? ''); ?></td>
            <td><code><?php echo htmlspecialchars($cc['dial_prefix'] ?? ''); ?></code></td>
            <td><?php
                $sec = (int) ($cc['billsec'] ?? 0);
                echo sprintf('%dm %02ds', floor($sec / 60), $sec % 60);
            ?></td>
            <td style="text-align: right;">$<?php echo number_format((float) ($cc['rate_per_minute'] ?? 0), 4); ?></td>
            <td style="text-align: right;">$<?php echo number_format((float) ($cc['charge_amount_rounded'] ?? 0), 2); ?></td>
            <td style="<?php echo $status_style; ?>"><?php echo ucfirst($status); ?></td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

<?php
require_once 'resources/footer.php';
