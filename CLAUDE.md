# CLAUDE.md

## Project Overview

FusionBilling is a PHP plugin for FusionPBX that adds automated billing via Sola Payments (Cardknox gateway). It lives in `app/sola_billing/` and follows FusionPBX plugin conventions.

## Tech Stack

- **Language**: PHP 8.0+ (no framework — follows FusionPBX patterns)
- **Database**: PostgreSQL (FusionPBX native, accessed via FusionPBX's `database` class)
- **Payment Gateway**: Sola Payments / Cardknox (Transaction API v5, iFields JS for PCI-compliant card entry)
- **PBX**: FusionPBX (FreeSWITCH-based)

## Project Structure

```
app/sola_billing/
├── app_config.php              # FusionPBX plugin registration (menu, permissions)
├── app_defaults.php            # Default settings inserted at install time
├── resources/
│   ├── classes/                # PHP classes (no namespaces — FusionPBX convention)
│   │   ├── SolaGateway.php     # Cardknox API wrapper
│   │   ├── BillingEngine.php   # Monthly billing orchestration
│   │   ├── BillingDatabase.php # All DB queries
│   │   ├── IntlRateTable.php   # Rate matching + CSV import
│   │   ├── CdrScanner.php      # International CDR detection
│   │   ├── InvoiceGenerator.php # PDF/HTML invoices
│   │   └── FailureHandler.php  # Restriction + retry logic
│   └── scripts/                # CLI/cron scripts
│       ├── install.php         # DB migration
│       ├── cron_*.php          # Three cron jobs
├── admin/                      # Web UI pages (FusionPBX admin panel)
└── webhooks/                   # External webhook receivers
```

## Key Patterns

### Database access
Uses FusionPBX's native `database` class. All queries go through `BillingDatabase.php`:
```php
$db = new database;
$db->execute($sql, $params);
$rows = $db->result();
```

### FusionPBX conventions
- Tables prefixed `v_sola_` to avoid conflicts
- Settings stored in `v_default_settings` under category `sola_billing`
- Permissions checked via `permission_exists('sola_billing_*')`
- CSRF protection via `create_token()` / `validate_token()`
- Pages include `resources/header.php` and `resources/footer.php`
- No namespaces (FusionPBX doesn't use them)

### Call restriction mechanism
Uses `v_domain_settings` with `max_outbound_calls = 0`. FreeSWITCH reads this from the database in real-time — no restart needed.

### International call detection
A call is international if it starts with `011` or `+` AND is NOT NANP (US/Canada/Caribbean: country code 1 + 10 digits starting with 2-9).

### Rate matching
Longest-prefix match: strip `011`/`+` from destination, try progressively shorter prefixes against `v_sola_intl_rate_table`. Fallback to configurable default rate.

## Development Notes

### Testing locally
The plugin requires a running FusionPBX instance with PostgreSQL. To test:
1. Copy `app/sola_billing/` into `/var/www/fusionpbx/app/`
2. Run `install.php` to create tables
3. Navigate to Sola Billing in the admin panel

### No build step
Pure PHP — no compilation, no composer dependencies (except optional TCPDF for PDF generation). Just copy files and go.

### Cron jobs
Three cron scripts in `resources/scripts/`:
- `cron_intl_cdr_scan.php` — hourly (scans CDRs)
- `cron_end_of_month.php` — monthly on last day at 11 PM
- `cron_retry_failed.php` — daily at 8 AM

### API key handling
API keys are stored in `v_default_settings` (encrypted at rest by PostgreSQL if configured). The gateway class reads them at runtime. Sandbox mode uses a separate key.

### Audit trail
Every significant action writes to `v_sola_audit_log` with actor, action, JSONB detail, and IP address. Use this for debugging and compliance.
