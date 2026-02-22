<?php
/**
 * rate_table_import.php — CSV import for international rate table.
 *
 * Upload a CSV, preview parsed rows, then commit the import.
 * Handles duplicate prefixes (update or skip).
 */

// FusionPBX bootstrap
require_once dirname(__DIR__, 3) . '/resources/require.php';
require_once 'resources/check_auth.php';

// Check permissions
if (!permission_exists('sola_billing_rates')) {
    echo "access denied";
    exit;
}

// Load classes
require_once dirname(__DIR__) . '/resources/classes/BillingDatabase.php';
require_once dirname(__DIR__) . '/resources/classes/IntlRateTable.php';
$billing_db = new BillingDatabase();
$unknown_rate = (float) ($billing_db->getSetting('unknown_dest_rate') ?? 0.10);
$rate_table = new IntlRateTable($billing_db, $unknown_rate);

$message = '';
$error = '';
$preview_rows = [];
$csv_content = '';
$step = 'upload'; // upload | preview | done

// Handle file upload (preview step)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['token']) || !validate_token($_POST['token'])) {
        $error = 'Invalid token.';
    } else {
        $action = $_POST['action'];

        if ($action === 'preview' && isset($_FILES['csv_file'])) {
            $file = $_FILES['csv_file'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $error = 'File upload failed. Error code: ' . $file['error'];
            } elseif ($file['size'] > 5 * 1024 * 1024) {
                $error = 'File too large. Maximum 5MB.';
            } else {
                $csv_content = file_get_contents($file['tmp_name']);
                $preview_rows = $rate_table->previewCsv($csv_content, 30);
                $step = 'preview';

                // Count total lines
                $total_lines = count(explode("\n", trim($csv_content)));
                // Subtract header if present
                if (!empty($preview_rows) && $preview_rows[0]['is_header']) {
                    $total_lines--;
                }
            }
        } elseif ($action === 'import') {
            $csv_content = $_POST['csv_data'] ?? '';
            $duplicate_action = $_POST['duplicate_action'] ?? 'update';

            if (empty($csv_content)) {
                $error = 'No CSV data to import.';
            } else {
                $results = $rate_table->importCsv($csv_content, $duplicate_action);
                $step = 'done';
                $message = sprintf(
                    'Import complete: %d added, %d updated, %d skipped.',
                    $results['added'],
                    $results['updated'],
                    $results['skipped']
                );

                if (!empty($results['errors'])) {
                    $error = implode("\n", $results['errors']);
                }

                $billing_db->writeAuditLog(
                    null,
                    $_SESSION['username'] ?? 'admin',
                    'rate_imported',
                    $results,
                    $_SERVER['REMOTE_ADDR'] ?? null
                );
            }
        }
    }
}

// Page title
$document['title'] = 'Sola Billing — Import Rate Table';
require_once 'resources/header.php';

?>

<div class="action_bar" id="action_bar">
    <div class="heading">
        <b>Sola Billing — Import International Rate Table</b>
    </div>
    <div class="actions">
        <a href="rate_table.php" class="btn btn-default btn-sm">
            <span class="fas fa-arrow-left"></span> Back to Rate Table
        </a>
    </div>
</div>

<?php if (!empty($message)): ?>
<div class="alert alert-success" style="margin: 10px 0;"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
<div class="alert alert-danger" style="margin: 10px 0; white-space: pre-line;"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<?php if ($step === 'upload' || $step === 'done'): ?>
<!-- UPLOAD STEP -->
<div style="margin: 20px 0;">
    <h4>Upload CSV File</h4>
    <p>Expected format: <code>country_name, country_code, dial_prefix, rate_per_minute, minimum_seconds</code></p>
    <p>Example:</p>
    <pre style="background: #f5f5f5; padding: 10px; max-width: 600px;">country_name,country_code,dial_prefix,rate_per_minute,minimum_seconds
Israel,IL,972,0.0250,6
United Kingdom,GB,44,0.0180,6
Mexico,MX,52,0.0350,6</pre>

    <form method="post" enctype="multipart/form-data" style="margin-top: 15px;">
        <input type="hidden" name="action" value="preview">
        <input type="hidden" name="token" value="<?php echo create_token(); ?>">

        <table class="table" style="width: auto;">
            <tr>
                <td>CSV File</td>
                <td>
                    <input type="file" name="csv_file" accept=".csv,.txt" required>
                    <br><span class="description">Maximum 5MB. UTF-8 encoding recommended.</span>
                </td>
            </tr>
            <tr>
                <td></td>
                <td>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <span class="fas fa-eye"></span> Preview Import
                    </button>
                </td>
            </tr>
        </table>
    </form>
</div>

<?php elseif ($step === 'preview'): ?>
<!-- PREVIEW STEP -->
<div style="margin: 20px 0;">
    <h4>Import Preview</h4>
    <p>Total rows to import: <strong><?php echo isset($total_lines) ? $total_lines : count($preview_rows); ?></strong></p>

    <table class="table table-striped" style="width: auto;">
        <thead>
            <tr>
                <th>Line</th>
                <th>Country</th>
                <th>Code</th>
                <th>Prefix</th>
                <th>Rate/Min</th>
                <th>Min Sec</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($preview_rows as $row): ?>
            <tr style="<?php echo $row['is_header'] ? 'font-weight: bold; background: #eee;' : ''; ?>">
                <td><?php echo $row['line']; ?></td>
                <td><?php echo htmlspecialchars($row['country_name']); ?></td>
                <td><?php echo htmlspecialchars($row['country_code']); ?></td>
                <td><code><?php echo htmlspecialchars($row['dial_prefix']); ?></code></td>
                <td><?php echo htmlspecialchars($row['rate_per_minute']); ?></td>
                <td><?php echo htmlspecialchars($row['minimum_seconds']); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (isset($total_lines) && $total_lines > count($preview_rows)): ?>
            <tr>
                <td colspan="6" style="text-align: center; color: #999;">
                    ... and <?php echo $total_lines - count($preview_rows); ?> more rows
                </td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <form method="post" style="margin-top: 15px;">
        <input type="hidden" name="action" value="import">
        <input type="hidden" name="token" value="<?php echo create_token(); ?>">
        <input type="hidden" name="csv_data" value="<?php echo htmlspecialchars($csv_content); ?>">

        <table class="table" style="width: auto;">
            <tr>
                <td>Duplicate Prefixes</td>
                <td>
                    <select name="duplicate_action" class="formfld">
                        <option value="update">Update existing rates</option>
                        <option value="skip">Skip duplicates</option>
                    </select>
                </td>
            </tr>
            <tr>
                <td></td>
                <td>
                    <button type="submit" class="btn btn-primary btn-sm"
                            onclick="return confirm('Import these rates? This cannot be undone.');">
                        <span class="fas fa-file-import"></span> Confirm Import
                    </button>
                    <a href="rate_table_import.php" class="btn btn-default btn-sm" style="margin-left: 10px;">
                        Cancel
                    </a>
                </td>
            </tr>
        </table>
    </form>
</div>
<?php endif; ?>

<?php
require_once 'resources/footer.php';
