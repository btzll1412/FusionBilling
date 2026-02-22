<?php
/**
 * reports.php — Revenue reports by domain, date, and type.
 *
 * Features:
 * - Revenue by domain (bar chart + table)
 * - Monthly summary table
 * - Export to CSV
 */

// FusionPBX bootstrap
require_once dirname(__DIR__, 3) . '/resources/require.php';
require_once 'resources/check_auth.php';

if (!permission_exists('sola_billing_reports')) {
    echo "access denied";
    exit;
}

require_once dirname(__DIR__) . '/resources/classes/BillingDatabase.php';
$billing_db = new BillingDatabase();

// Date range
$date_from = $_GET['date_from'] ?? date('Y-01-01'); // Default: start of year
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$report_type = $_GET['report'] ?? 'domain';

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="sola_billing_report_' . date('Ymd') . '.csv"');

    $output = fopen('php://output', 'w');

    if ($report_type === 'domain') {
        $data = $billing_db->getRevenueByDomain($date_from, $date_to);
        fputcsv($output, ['Domain', 'Total Revenue', 'Successful Charges', 'Failed Charges']);
        foreach ($data as $row) {
            fputcsv($output, [
                $row['domain_name'],
                number_format((float) $row['total_revenue'], 2),
                $row['successful_charges'],
                $row['failed_charges'],
            ]);
        }
    } else {
        $data = $billing_db->getMonthlyRevenueSummary(12);
        fputcsv($output, ['Month', 'Revenue', 'Successful', 'Failed']);
        foreach ($data as $row) {
            fputcsv($output, [
                $row['month'],
                number_format((float) $row['revenue'], 2),
                $row['successful'],
                $row['failed'],
            ]);
        }
    }

    fclose($output);
    exit;
}

// Fetch report data
$domain_revenue = $billing_db->getRevenueByDomain($date_from, $date_to);
$monthly_summary = $billing_db->getMonthlyRevenueSummary(12);

$document['title'] = 'Sola Billing — Reports';
require_once 'resources/header.php';

?>

<div class="action_bar" id="action_bar">
    <div class="heading">
        <b>Sola Billing — Reports</b>
    </div>
    <div class="actions">
        <a href="?report=<?php echo urlencode($report_type); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&export=csv"
           class="btn btn-default btn-sm">
            <span class="fas fa-file-csv"></span> Export CSV
        </a>
    </div>
</div>

<!-- Date Range Filter -->
<form method="get" style="margin: 10px 0; display: flex; gap: 10px; align-items: end;">
    <div>
        <label style="font-size: 11px;">From</label><br>
        <input type="date" name="date_from" class="formfld" style="width: 140px;"
               value="<?php echo htmlspecialchars($date_from); ?>">
    </div>
    <div>
        <label style="font-size: 11px;">To</label><br>
        <input type="date" name="date_to" class="formfld" style="width: 140px;"
               value="<?php echo htmlspecialchars($date_to); ?>">
    </div>
    <div>
        <button type="submit" class="btn btn-default btn-sm"><span class="fas fa-search"></span> Update</button>
    </div>
</form>

<!-- Revenue by Domain -->
<h4>Revenue by Domain (<?php echo htmlspecialchars($date_from); ?> to <?php echo htmlspecialchars($date_to); ?>)</h4>

<?php if (empty($domain_revenue)): ?>
<p style="color: #999;">No revenue data for this period.</p>
<?php else: ?>

<?php
    $max_revenue = 0;
    foreach ($domain_revenue as $d) {
        $rev = (float) $d['total_revenue'];
        if ($rev > $max_revenue) $max_revenue = $rev;
    }
    $total_revenue = 0;
?>

<table class="table table-striped" style="width: auto; min-width: 600px;">
    <thead>
        <tr>
            <th>Domain</th>
            <th style="width: 300px;">Revenue</th>
            <th style="text-align: right;">Amount</th>
            <th style="text-align: center;">OK</th>
            <th style="text-align: center;">Failed</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($domain_revenue as $d): ?>
        <?php
            $rev = (float) $d['total_revenue'];
            $total_revenue += $rev;
            $bar_width = $max_revenue > 0 ? ($rev / $max_revenue * 100) : 0;
        ?>
        <tr>
            <td><?php echo htmlspecialchars($d['domain_name']); ?></td>
            <td>
                <div style="background: #5cb85c; height: 18px; width: <?php echo $bar_width; ?>%; min-width: 2px; border-radius: 2px;"></div>
            </td>
            <td style="text-align: right; font-weight: bold;">$<?php echo number_format($rev, 2); ?></td>
            <td style="text-align: center; color: #5cb85c;"><?php echo (int) $d['successful_charges']; ?></td>
            <td style="text-align: center; color: #d9534f;"><?php echo (int) $d['failed_charges']; ?></td>
        </tr>
        <?php endforeach; ?>
        <tr style="font-weight: bold; border-top: 2px solid #333;">
            <td>TOTAL</td>
            <td></td>
            <td style="text-align: right;">$<?php echo number_format($total_revenue, 2); ?></td>
            <td></td>
            <td></td>
        </tr>
    </tbody>
</table>
<?php endif; ?>

<!-- Monthly Summary -->
<h4 style="margin-top: 30px;">Monthly Revenue Summary</h4>

<?php if (empty($monthly_summary)): ?>
<p style="color: #999;">No monthly data available yet.</p>
<?php else: ?>
<table class="table table-striped" style="width: auto;">
    <thead>
        <tr>
            <th>Month</th>
            <th style="text-align: right;">Revenue</th>
            <th style="text-align: center;">Successful</th>
            <th style="text-align: center;">Failed</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($monthly_summary as $m): ?>
        <tr>
            <td><?php echo htmlspecialchars($m['month']); ?></td>
            <td style="text-align: right; font-weight: bold;">$<?php echo number_format((float) $m['revenue'], 2); ?></td>
            <td style="text-align: center; color: #5cb85c;"><?php echo (int) $m['successful']; ?></td>
            <td style="text-align: center; color: #d9534f;"><?php echo (int) $m['failed']; ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?php
require_once 'resources/footer.php';
