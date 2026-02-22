<?php
/**
 * app_config.php — FusionPBX plugin registration for Sola Billing.
 *
 * Registers the plugin with FusionPBX's app manager, adds menu items,
 * and defines permissions.
 */

// Application info
$apps[$x]['name']           = 'Sola Billing';
$apps[$x]['uuid']           = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';
$apps[$x]['category']       = 'Billing';
$apps[$x]['subcategory']    = '';
$apps[$x]['version']        = '1.0.0';
$apps[$x]['license']        = 'Proprietary';
$apps[$x]['url']            = '';
$apps[$x]['description']['en-us'] = 'Automated billing system for FusionPBX using Sola Payments (Cardknox). Per-extension subscription billing and international call billing with consolidated monthly invoices.';

// Permission groups
$y = 0;
$apps[$x]['permissions'][$y]['name']        = 'sola_billing_dashboard';
$apps[$x]['permissions'][$y]['groups'][]    = 'superadmin';

$y++;
$apps[$x]['permissions'][$y]['name']        = 'sola_billing_domain';
$apps[$x]['permissions'][$y]['groups'][]    = 'superadmin';

$y++;
$apps[$x]['permissions'][$y]['name']        = 'sola_billing_rates';
$apps[$x]['permissions'][$y]['groups'][]    = 'superadmin';

$y++;
$apps[$x]['permissions'][$y]['name']        = 'sola_billing_transactions';
$apps[$x]['permissions'][$y]['groups'][]    = 'superadmin';

$y++;
$apps[$x]['permissions'][$y]['name']        = 'sola_billing_reports';
$apps[$x]['permissions'][$y]['groups'][]    = 'superadmin';

$y++;
$apps[$x]['permissions'][$y]['name']        = 'sola_billing_settings';
$apps[$x]['permissions'][$y]['groups'][]    = 'superadmin';

// Menu items
$y = 0;
$apps[$x]['menu'][$y]['title']['en-us']     = 'Sola Billing';
$apps[$x]['menu'][$y]['uuid']               = 'b2c3d4e5-f6a7-8901-bcde-f12345678901';
$apps[$x]['menu'][$y]['parent_uuid']        = '';
$apps[$x]['menu'][$y]['category']           = 'internal';
$apps[$x]['menu'][$y]['icon']               = 'fas fa-file-invoice-dollar';
$apps[$x]['menu'][$y]['path']               = '';
$apps[$x]['menu'][$y]['order']              = '';
$apps[$x]['menu'][$y]['groups'][]           = 'superadmin';

$y++;
$apps[$x]['menu'][$y]['title']['en-us']     = 'Dashboard';
$apps[$x]['menu'][$y]['uuid']               = 'c3d4e5f6-a7b8-9012-cdef-123456789012';
$apps[$x]['menu'][$y]['parent_uuid']        = 'b2c3d4e5-f6a7-8901-bcde-f12345678901';
$apps[$x]['menu'][$y]['category']           = 'internal';
$apps[$x]['menu'][$y]['icon']               = '';
$apps[$x]['menu'][$y]['path']               = '/app/sola_billing/admin/index.php';
$apps[$x]['menu'][$y]['order']              = '';
$apps[$x]['menu'][$y]['groups'][]           = 'superadmin';

$y++;
$apps[$x]['menu'][$y]['title']['en-us']     = 'Domain Billing';
$apps[$x]['menu'][$y]['uuid']               = 'd4e5f6a7-b8c9-0123-defa-234567890123';
$apps[$x]['menu'][$y]['parent_uuid']        = 'b2c3d4e5-f6a7-8901-bcde-f12345678901';
$apps[$x]['menu'][$y]['category']           = 'internal';
$apps[$x]['menu'][$y]['icon']               = '';
$apps[$x]['menu'][$y]['path']               = '/app/sola_billing/admin/domain_billing.php';
$apps[$x]['menu'][$y]['order']              = '';
$apps[$x]['menu'][$y]['groups'][]           = 'superadmin';

$y++;
$apps[$x]['menu'][$y]['title']['en-us']     = 'Rate Table';
$apps[$x]['menu'][$y]['uuid']               = 'e5f6a7b8-c9d0-1234-efab-345678901234';
$apps[$x]['menu'][$y]['parent_uuid']        = 'b2c3d4e5-f6a7-8901-bcde-f12345678901';
$apps[$x]['menu'][$y]['category']           = 'internal';
$apps[$x]['menu'][$y]['icon']               = '';
$apps[$x]['menu'][$y]['path']               = '/app/sola_billing/admin/rate_table.php';
$apps[$x]['menu'][$y]['order']              = '';
$apps[$x]['menu'][$y]['groups'][]           = 'superadmin';

$y++;
$apps[$x]['menu'][$y]['title']['en-us']     = 'Transactions';
$apps[$x]['menu'][$y]['uuid']               = 'f6a7b8c9-d0e1-2345-fabc-456789012345';
$apps[$x]['menu'][$y]['parent_uuid']        = 'b2c3d4e5-f6a7-8901-bcde-f12345678901';
$apps[$x]['menu'][$y]['category']           = 'internal';
$apps[$x]['menu'][$y]['icon']               = '';
$apps[$x]['menu'][$y]['path']               = '/app/sola_billing/admin/transactions.php';
$apps[$x]['menu'][$y]['order']              = '';
$apps[$x]['menu'][$y]['groups'][]           = 'superadmin';

$y++;
$apps[$x]['menu'][$y]['title']['en-us']     = 'Call Charges';
$apps[$x]['menu'][$y]['uuid']               = 'a7b8c9d0-e1f2-3456-abcd-567890123456';
$apps[$x]['menu'][$y]['parent_uuid']        = 'b2c3d4e5-f6a7-8901-bcde-f12345678901';
$apps[$x]['menu'][$y]['category']           = 'internal';
$apps[$x]['menu'][$y]['icon']               = '';
$apps[$x]['menu'][$y]['path']               = '/app/sola_billing/admin/call_charges.php';
$apps[$x]['menu'][$y]['order']              = '';
$apps[$x]['menu'][$y]['groups'][]           = 'superadmin';

$y++;
$apps[$x]['menu'][$y]['title']['en-us']     = 'Failed Charges';
$apps[$x]['menu'][$y]['uuid']               = 'b8c9d0e1-f2a3-4567-bcde-678901234567';
$apps[$x]['menu'][$y]['parent_uuid']        = 'b2c3d4e5-f6a7-8901-bcde-f12345678901';
$apps[$x]['menu'][$y]['category']           = 'internal';
$apps[$x]['menu'][$y]['icon']               = '';
$apps[$x]['menu'][$y]['path']               = '/app/sola_billing/admin/failed_charges.php';
$apps[$x]['menu'][$y]['order']              = '';
$apps[$x]['menu'][$y]['groups'][]           = 'superadmin';

$y++;
$apps[$x]['menu'][$y]['title']['en-us']     = 'Reports';
$apps[$x]['menu'][$y]['uuid']               = 'c9d0e1f2-a3b4-5678-cdef-789012345678';
$apps[$x]['menu'][$y]['parent_uuid']        = 'b2c3d4e5-f6a7-8901-bcde-f12345678901';
$apps[$x]['menu'][$y]['category']           = 'internal';
$apps[$x]['menu'][$y]['icon']               = '';
$apps[$x]['menu'][$y]['path']               = '/app/sola_billing/admin/reports.php';
$apps[$x]['menu'][$y]['order']              = '';
$apps[$x]['menu'][$y]['groups'][]           = 'superadmin';

$y++;
$apps[$x]['menu'][$y]['title']['en-us']     = 'Settings';
$apps[$x]['menu'][$y]['uuid']               = 'd0e1f2a3-b4c5-6789-defa-890123456789';
$apps[$x]['menu'][$y]['parent_uuid']        = 'b2c3d4e5-f6a7-8901-bcde-f12345678901';
$apps[$x]['menu'][$y]['category']           = 'internal';
$apps[$x]['menu'][$y]['icon']               = '';
$apps[$x]['menu'][$y]['path']               = '/app/sola_billing/admin/settings.php';
$apps[$x]['menu'][$y]['order']              = '';
$apps[$x]['menu'][$y]['groups'][]           = 'superadmin';
