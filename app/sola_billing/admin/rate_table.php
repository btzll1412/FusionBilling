<?php
/**
 * rate_table.php — View and manage international rate table.
 *
 * Paginated list of all rate entries. Search by country or prefix.
 * Edit individual rates inline. Toggle enabled/disabled per rate.
 */

// FusionPBX bootstrap
require_once dirname(__DIR__, 3) . '/resources/require.php';
require_once 'resources/check_auth.php';
require_once 'resources/paging.php';

// Check permissions
if (!permission_exists('sola_billing_rates')) {
    echo "access denied";
    exit;
}

// Load classes
require_once dirname(__DIR__) . '/resources/classes/BillingDatabase.php';
$billing_db = new BillingDatabase();

$message = '';
$error = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['token']) && validate_token($_POST['token'])) {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'save_rate':
            $rate_uuid = $_POST['rate_uuid'] ?? '';
            $data = [
                'country_name'    => $_POST['country_name'] ?? '',
                'country_code'    => $_POST['country_code'] ?? '',
                'dial_prefix'     => $_POST['dial_prefix'] ?? '',
                'rate_per_minute' => (float) ($_POST['rate_per_minute'] ?? 0),
                'minimum_seconds' => (int) ($_POST['minimum_seconds'] ?? 6),
                'enabled'         => isset($_POST['enabled']) ? 'true' : 'false',
            ];

            if (empty($data['country_name']) || empty($data['dial_prefix']) || $data['rate_per_minute'] <= 0) {
                $error = 'Country name, dial prefix, and rate per minute are required.';
            } else {
                $billing_db->saveRate($data, !empty($rate_uuid) ? $rate_uuid : null);
                $message = !empty($rate_uuid) ? 'Rate updated.' : 'Rate added.';

                $billing_db->writeAuditLog(
                    null,
                    $_SESSION['username'] ?? 'admin',
                    'rate_saved',
                    $data,
                    $_SERVER['REMOTE_ADDR'] ?? null
                );
            }
            break;

        case 'delete_rate':
            $rate_uuid = $_POST['rate_uuid'] ?? '';
            if (!empty($rate_uuid)) {
                $billing_db->deleteRate($rate_uuid);
                $message = 'Rate deleted.';
            }
            break;

        case 'toggle_rate':
            $rate_uuid = $_POST['rate_uuid'] ?? '';
            $new_state = $_POST['new_state'] ?? 'false';
            if (!empty($rate_uuid)) {
                $billing_db->saveRate(['enabled' => $new_state], $rate_uuid);
                $message = 'Rate ' . ($new_state === 'true' ? 'enabled' : 'disabled') . '.';
            }
            break;
    }
}

// Pagination & search
$search = $_GET['search'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 50;
$offset = ($page - 1) * $per_page;

$total = $billing_db->countRates($search);
$rates = $billing_db->getRateTable($search, $per_page, $offset);
$total_pages = max(1, ceil($total / $per_page));

// Page title
$document['title'] = 'Sola Billing — Rate Table';
require_once 'resources/header.php';

?>

<div class="action_bar" id="action_bar">
    <div class="heading">
        <b>Sola Billing — International Rate Table</b>
        <span style="color: #999; font-size: 0.9em; margin-left: 10px;">
            <?php echo number_format($total); ?> rate<?php echo $total !== 1 ? 's' : ''; ?>
        </span>
    </div>
    <div class="actions">
        <a href="rate_table_import.php" class="btn btn-default btn-sm">
            <span class="fas fa-file-upload"></span> Import CSV
        </a>
    </div>
</div>

<?php if (!empty($message)): ?>
<div class="alert alert-success" style="margin: 10px 0;"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
<div class="alert alert-danger" style="margin: 10px 0;"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<!-- Search -->
<form method="get" style="margin: 10px 0;">
    <input type="text" name="search" class="formfld" style="width: 300px;"
           value="<?php echo htmlspecialchars($search); ?>"
           placeholder="Search by country name or prefix...">
    <button type="submit" class="btn btn-default btn-sm">
        <span class="fas fa-search"></span> Search
    </button>
    <?php if (!empty($search)): ?>
    <a href="rate_table.php" class="btn btn-default btn-sm">Clear</a>
    <?php endif; ?>
</form>

<!-- Add New Rate Form -->
<details style="margin: 10px 0;">
    <summary style="cursor: pointer; font-weight: bold;">+ Add New Rate</summary>
    <form method="post" style="margin: 10px 0;">
        <input type="hidden" name="action" value="save_rate">
        <input type="hidden" name="rate_uuid" value="">
        <input type="hidden" name="token" value="<?php echo create_token(); ?>">
        <table class="table" style="width: auto;">
            <tr>
                <td>Country Name</td>
                <td><input type="text" name="country_name" class="formfld" style="width: 200px;" required></td>
            </tr>
            <tr>
                <td>Country Code</td>
                <td><input type="text" name="country_code" class="formfld" style="width: 60px;" maxlength="2" placeholder="US"></td>
            </tr>
            <tr>
                <td>Dial Prefix</td>
                <td><input type="text" name="dial_prefix" class="formfld" style="width: 120px;" required placeholder="972"></td>
            </tr>
            <tr>
                <td>Rate/Min ($)</td>
                <td><input type="number" name="rate_per_minute" class="formfld" step="0.0001" min="0" style="width: 120px;" required></td>
            </tr>
            <tr>
                <td>Min Seconds</td>
                <td><input type="number" name="minimum_seconds" class="formfld" min="1" value="6" style="width: 80px;"></td>
            </tr>
            <tr>
                <td>Enabled</td>
                <td><input type="checkbox" name="enabled" value="true" checked></td>
            </tr>
            <tr>
                <td></td>
                <td><button type="submit" class="btn btn-primary btn-sm"><span class="fas fa-plus"></span> Add Rate</button></td>
            </tr>
        </table>
    </form>
</details>

<!-- Rate Table -->
<table class="table table-striped table-hover">
    <thead>
        <tr>
            <th>Country</th>
            <th>Code</th>
            <th>Prefix</th>
            <th style="text-align: right;">Rate/Min</th>
            <th style="text-align: center;">Min Sec</th>
            <th style="text-align: center;">Enabled</th>
            <th style="text-align: center;">Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($rates)): ?>
        <tr>
            <td colspan="7" style="text-align: center; padding: 20px;">
                No rates found. <?php echo !empty($search) ? 'Try a different search.' : 'Import a CSV or add rates manually.'; ?>
            </td>
        </tr>
        <?php else: ?>
        <?php foreach ($rates as $rate): ?>
        <?php $enabled = ($rate['enabled'] ?? '') === 't' || ($rate['enabled'] ?? '') === 'true'; ?>
        <tr style="<?php echo !$enabled ? 'opacity: 0.5;' : ''; ?>">
            <td><?php echo htmlspecialchars($rate['country_name'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($rate['country_code'] ?? ''); ?></td>
            <td><code><?php echo htmlspecialchars($rate['dial_prefix'] ?? ''); ?></code></td>
            <td style="text-align: right;">$<?php echo number_format((float) ($rate['rate_per_minute'] ?? 0), 4); ?></td>
            <td style="text-align: center;"><?php echo (int) ($rate['minimum_seconds'] ?? 6); ?></td>
            <td style="text-align: center;">
                <form method="post" style="display: inline;">
                    <input type="hidden" name="action" value="toggle_rate">
                    <input type="hidden" name="rate_uuid" value="<?php echo htmlspecialchars($rate['rate_uuid']); ?>">
                    <input type="hidden" name="new_state" value="<?php echo $enabled ? 'false' : 'true'; ?>">
                    <input type="hidden" name="token" value="<?php echo create_token(); ?>">
                    <button type="submit" class="btn btn-xs <?php echo $enabled ? 'btn-success' : 'btn-default'; ?>"
                            title="Click to <?php echo $enabled ? 'disable' : 'enable'; ?>">
                        <?php echo $enabled ? 'Yes' : 'No'; ?>
                    </button>
                </form>
            </td>
            <td style="text-align: center;">
                <form method="post" style="display: inline;"
                      onsubmit="return confirm('Delete this rate?');">
                    <input type="hidden" name="action" value="delete_rate">
                    <input type="hidden" name="rate_uuid" value="<?php echo htmlspecialchars($rate['rate_uuid']); ?>">
                    <input type="hidden" name="token" value="<?php echo create_token(); ?>">
                    <button type="submit" class="btn btn-danger btn-xs">
                        <span class="fas fa-trash"></span>
                    </button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

<!-- Pagination -->
<?php if ($total_pages > 1): ?>
<div style="text-align: center; margin: 20px 0;">
    <?php for ($p = 1; $p <= $total_pages; $p++): ?>
        <?php if ($p === $page): ?>
            <strong>[<?php echo $p; ?>]</strong>
        <?php else: ?>
            <a href="?page=<?php echo $p; ?>&search=<?php echo urlencode($search); ?>"><?php echo $p; ?></a>
        <?php endif; ?>
    <?php endfor; ?>
</div>
<?php endif; ?>

<?php
require_once 'resources/footer.php';
