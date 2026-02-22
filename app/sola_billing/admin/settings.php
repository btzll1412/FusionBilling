<?php
/**
 * settings.php — Sola Billing global settings page
 *
 * Allows admin to configure API keys, defaults, and system behavior.
 */

// FusionPBX bootstrap
require_once dirname(__DIR__, 3) . '/resources/require.php';
require_once 'resources/check_auth.php';
require_once 'resources/paging.php';

// Check permissions
if (!permission_exists('sola_billing_settings')) {
    echo "access denied";
    exit;
}

// Load classes
require_once dirname(__DIR__) . '/resources/classes/BillingDatabase.php';
$billing_db = new BillingDatabase();

// Handle form submission
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_settings') {
    // CSRF check
    if (!isset($_POST['token']) || !validate_token($_POST['token'])) {
        $message = 'Invalid token. Please try again.';
    } else {
        $settings_map = [
            'api_key'               => $_POST['api_key'] ?? '',
            'api_key_sandbox'       => $_POST['api_key_sandbox'] ?? '',
            'sandbox_mode'          => isset($_POST['sandbox_mode']) ? 'true' : 'false',
            'default_rate'          => $_POST['default_rate'] ?? '0.00',
            'unknown_dest_rate'     => $_POST['unknown_dest_rate'] ?? '0.10',
            'max_retry_attempts'    => $_POST['max_retry_attempts'] ?? '3',
            'retry_interval_days'   => $_POST['retry_interval_days'] ?? '3',
            'admin_email'           => $_POST['admin_email'] ?? '',
            'invoice_company_name'  => $_POST['invoice_company_name'] ?? '',
            'invoice_storage_path'  => $_POST['invoice_storage_path'] ?? '/var/www/fusionpbx/storage/invoices/',
        ];

        foreach ($settings_map as $key => $value) {
            $billing_db->saveSetting($key, $value);
        }

        $message = 'Settings saved successfully.';

        // Audit log
        $billing_db->writeAuditLog(
            null,
            $_SESSION['username'] ?? 'admin',
            'settings_updated',
            ['settings' => array_keys($settings_map)],
            $_SERVER['REMOTE_ADDR'] ?? null
        );
    }
}

// Load current settings
$settings = $billing_db->getAllSettings();

// Page title
$document['title'] = 'Sola Billing — Settings';

// Include header
require_once 'resources/header.php';

?>

<div class="action_bar" id="action_bar">
    <div class="heading">
        <b>Sola Billing — Settings</b>
    </div>
    <div class="actions">
        <button type="submit" form="settings_form" class="btn btn-default btn-sm">
            <span class="fas fa-save"></span> Save
        </button>
    </div>
</div>

<?php if (!empty($message)): ?>
<div class="alert alert-info" style="margin: 10px 0;">
    <?php echo htmlspecialchars($message); ?>
</div>
<?php endif; ?>

<form id="settings_form" method="post">
    <input type="hidden" name="action" value="save_settings">
    <input type="hidden" name="token" value="<?php echo create_token(); ?>">

    <table class="table table-striped" style="width: auto;">
        <tr>
            <th colspan="2" style="background: #f5f5f5;">
                <strong>API Configuration</strong>
            </th>
        </tr>
        <tr>
            <td class="vncell" style="width: 250px;">Sola API Key (Production)</td>
            <td class="vtable">
                <input type="password" name="api_key" class="formfld"
                       value="<?php echo htmlspecialchars($settings['api_key'] ?? ''); ?>"
                       style="width: 400px;" autocomplete="off">
                <br><span class="description">Your live Sola/Cardknox API key (xKey).</span>
            </td>
        </tr>
        <tr>
            <td class="vncell">Sola API Key (Sandbox)</td>
            <td class="vtable">
                <input type="password" name="api_key_sandbox" class="formfld"
                       value="<?php echo htmlspecialchars($settings['api_key_sandbox'] ?? ''); ?>"
                       style="width: 400px;" autocomplete="off">
                <br><span class="description">Your sandbox/test API key for development.</span>
            </td>
        </tr>
        <tr>
            <td class="vncell">Sandbox Mode</td>
            <td class="vtable">
                <label>
                    <input type="checkbox" name="sandbox_mode" value="true"
                           <?php echo ($settings['sandbox_mode'] ?? 'false') === 'true' ? 'checked' : ''; ?>>
                    Enable sandbox mode (uses sandbox API key, no real charges)
                </label>
            </td>
        </tr>

        <tr>
            <th colspan="2" style="background: #f5f5f5;">
                <strong>Billing Defaults</strong>
            </th>
        </tr>
        <tr>
            <td class="vncell">Default Rate Per Extension</td>
            <td class="vtable">
                $<input type="number" name="default_rate" class="formfld" step="0.01" min="0"
                       value="<?php echo htmlspecialchars($settings['default_rate'] ?? '0.00'); ?>"
                       style="width: 120px;">
                <br><span class="description">Default per-extension monthly rate for new domains.</span>
            </td>
        </tr>
        <tr>
            <td class="vncell">Unknown Destination Rate</td>
            <td class="vtable">
                $<input type="number" name="unknown_dest_rate" class="formfld" step="0.0001" min="0"
                       value="<?php echo htmlspecialchars($settings['unknown_dest_rate'] ?? '0.10'); ?>"
                       style="width: 120px;"> /min
                <br><span class="description">Rate per minute for international calls with no matching prefix.</span>
            </td>
        </tr>

        <tr>
            <th colspan="2" style="background: #f5f5f5;">
                <strong>Failure Handling</strong>
            </th>
        </tr>
        <tr>
            <td class="vncell">Max Retry Attempts</td>
            <td class="vtable">
                <input type="number" name="max_retry_attempts" class="formfld" min="0" max="10"
                       value="<?php echo htmlspecialchars($settings['max_retry_attempts'] ?? '3'); ?>"
                       style="width: 80px;">
                <br><span class="description">Number of times to retry a failed charge before marking as abandoned.</span>
            </td>
        </tr>
        <tr>
            <td class="vncell">Retry Interval (Days)</td>
            <td class="vtable">
                <input type="number" name="retry_interval_days" class="formfld" min="1" max="30"
                       value="<?php echo htmlspecialchars($settings['retry_interval_days'] ?? '3'); ?>"
                       style="width: 80px;"> days
                <br><span class="description">Days between retry attempts (Retry 1 on day 3, Retry 2 on day 6, etc.).</span>
            </td>
        </tr>

        <tr>
            <th colspan="2" style="background: #f5f5f5;">
                <strong>Notifications</strong>
            </th>
        </tr>
        <tr>
            <td class="vncell">Admin Alert Email(s)</td>
            <td class="vtable">
                <input type="text" name="admin_email" class="formfld"
                       value="<?php echo htmlspecialchars($settings['admin_email'] ?? ''); ?>"
                       style="width: 400px;">
                <br><span class="description">Comma-separated email addresses for billing notifications.</span>
            </td>
        </tr>

        <tr>
            <th colspan="2" style="background: #f5f5f5;">
                <strong>Invoices</strong>
            </th>
        </tr>
        <tr>
            <td class="vncell">Company Name</td>
            <td class="vtable">
                <input type="text" name="invoice_company_name" class="formfld"
                       value="<?php echo htmlspecialchars($settings['invoice_company_name'] ?? ''); ?>"
                       style="width: 400px;">
                <br><span class="description">Company name displayed on generated invoices.</span>
            </td>
        </tr>
        <tr>
            <td class="vncell">Invoice Storage Path</td>
            <td class="vtable">
                <input type="text" name="invoice_storage_path" class="formfld"
                       value="<?php echo htmlspecialchars($settings['invoice_storage_path'] ?? '/var/www/fusionpbx/storage/invoices/'); ?>"
                       style="width: 400px;">
                <br><span class="description">Filesystem path for storing generated PDF invoices.</span>
            </td>
        </tr>
    </table>
</form>

<?php
require_once 'resources/footer.php';
