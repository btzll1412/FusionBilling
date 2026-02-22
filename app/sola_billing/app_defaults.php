<?php
/**
 * app_defaults.php — Install-time default settings for Sola Billing.
 *
 * This file is called by FusionPBX's app manager during installation
 * to set up initial default settings.
 */

// Only run when called from the app manager context
if (!function_exists('default_setting_exists')) {
    return;
}

// Default settings for Sola Billing
$default_settings = [
    [
        'category'    => 'sola_billing',
        'subcategory' => 'sandbox_mode',
        'name'        => 'boolean',
        'value'       => 'true',
        'enabled'     => 'true',
        'description' => 'Enable sandbox mode (no real charges)',
    ],
    [
        'category'    => 'sola_billing',
        'subcategory' => 'default_rate',
        'name'        => 'numeric',
        'value'       => '0.00',
        'enabled'     => 'true',
        'description' => 'Default per-extension monthly rate',
    ],
    [
        'category'    => 'sola_billing',
        'subcategory' => 'unknown_dest_rate',
        'name'        => 'numeric',
        'value'       => '0.10',
        'enabled'     => 'true',
        'description' => 'Rate per minute for unknown international destinations',
    ],
    [
        'category'    => 'sola_billing',
        'subcategory' => 'max_retry_attempts',
        'name'        => 'numeric',
        'value'       => '3',
        'enabled'     => 'true',
        'description' => 'Maximum number of retry attempts for failed charges',
    ],
    [
        'category'    => 'sola_billing',
        'subcategory' => 'retry_interval_days',
        'name'        => 'numeric',
        'value'       => '3',
        'enabled'     => 'true',
        'description' => 'Days between retry attempts',
    ],
    [
        'category'    => 'sola_billing',
        'subcategory' => 'invoice_storage_path',
        'name'        => 'text',
        'value'       => '/var/www/fusionpbx/storage/invoices/',
        'enabled'     => 'true',
        'description' => 'Filesystem path for storing generated invoices',
    ],
];

// Insert defaults if they don't already exist
foreach ($default_settings as $setting) {
    if (!default_setting_exists($setting['category'], $setting['subcategory'])) {
        $db = new database;
        $sql = "INSERT INTO v_default_settings
                (default_setting_uuid, default_setting_category, default_setting_subcategory,
                 default_setting_name, default_setting_value, default_setting_enabled,
                 default_setting_description)
                VALUES
                (gen_random_uuid(), :category, :subcategory, :name, :value, :enabled, :description)";
        $db->execute($sql, [
            ':category'    => $setting['category'],
            ':subcategory' => $setting['subcategory'],
            ':name'        => $setting['name'],
            ':value'       => $setting['value'],
            ':enabled'     => $setting['enabled'],
            ':description' => $setting['description'],
        ]);
    }
}
