# FusionBilling

## Overview

FusionBilling is a FusionPBX plugin that adds automated billing via **Sola Payments** (Cardknox gateway). It handles per-extension subscription billing and international call billing with consolidated monthly invoices.

### What it does

- **Monthly subscription billing**: Counts active extensions per domain, multiplies by a configurable rate, and charges the card on file at end of month.
- **International call billing**: Scans CDRs hourly for international calls, calculates charges using a rate table with longest-prefix matching, and includes them in the monthly invoice.
- **Failure handling**: Instantly restricts outbound calls when a charge is declined. Retries on a configurable schedule (default: every 3 days, max 3 attempts). Alerts admin by email.
- **PCI-compliant card storage**: Uses Sola/Cardknox iFields — card numbers never touch the FusionPBX server. Only tokens are stored.

## Architecture

```
app/sola_billing/
├── app_config.php                      # FusionPBX plugin registration + menu
├── app_defaults.php                    # Install-time default settings
├── resources/
│   ├── classes/
│   │   ├── SolaGateway.php             # Cardknox Transaction API v5 client
│   │   ├── BillingEngine.php           # End-of-month billing orchestrator
│   │   ├── BillingDatabase.php         # All PostgreSQL read/write operations
│   │   ├── IntlRateTable.php           # International detection + rate matching
│   │   ├── CdrScanner.php              # CDR scanner for international calls
│   │   ├── InvoiceGenerator.php        # PDF/HTML invoice generation
│   │   └── FailureHandler.php          # Decline → restrict → retry logic
│   └── scripts/
│       ├── install.php                 # Database migration (tables + indexes)
│       ├── cron_intl_cdr_scan.php      # Hourly CDR scan
│       ├── cron_end_of_month.php       # Monthly consolidated charge
│       └── cron_retry_failed.php       # Daily retry of failed charges
├── admin/
│   ├── index.php                       # Dashboard (stats + alerts)
│   ├── settings.php                    # Global API keys + defaults
│   ├── domain_billing.php              # Domain billing overview table
│   ├── domain_billing_edit.php         # Per-domain config + iFields card entry
│   ├── rate_table.php                  # International rate table CRUD
│   ├── rate_table_import.php           # CSV import with preview
│   ├── transactions.php                # Transaction list with filters
│   ├── transaction_detail.php          # Transaction detail + void/refund
│   ├── call_charges.php                # International call charges viewer
│   ├── failed_charges.php              # Failed charge queue + manual actions
│   └── reports.php                     # Revenue reports + CSV export
└── webhooks/
    └── sola_webhook.php                # Sola webhook receiver (chargebacks)
```

## Database Tables

All tables use the `v_sola_` prefix and live in the existing FusionPBX PostgreSQL database:

| Table | Purpose |
|-------|---------|
| `v_sola_domain_billing` | Per-domain billing config (rate, enabled, restriction state) |
| `v_sola_payment_methods` | Tokenized cards on file per domain |
| `v_sola_transactions` | All charge/refund/void transaction records |
| `v_sola_intl_rate_table` | International rate table (prefix → rate/min) |
| `v_sola_call_charges` | Individual international call charge records |
| `v_sola_audit_log` | Full audit trail (JSONB detail) |

Settings are stored in the native `v_default_settings` table under category `sola_billing`.

## Installation

### 1. Copy the plugin

```bash
cp -r app/sola_billing /var/www/fusionpbx/app/sola_billing
chown -R www-data:www-data /var/www/fusionpbx/app/sola_billing
```

### 2. Run the database migration

```bash
cd /var/www/fusionpbx
sudo -u www-data php app/sola_billing/resources/scripts/install.php
```

### 3. Register the plugin

In the FusionPBX admin panel, go to **Advanced → Upgrade** and click **App Defaults** to register the menu items and permissions.

Alternatively, clear the cache:
```bash
rm -rf /var/www/fusionpbx/resources/cache/*
```

### 4. Configure cron jobs

```bash
# Hourly: scan CDRs for international calls
0 * * * *       www-data  php /var/www/fusionpbx/app/sola_billing/resources/scripts/cron_intl_cdr_scan.php >> /var/log/sola_billing_cdr.log 2>&1

# Monthly: end-of-month consolidated charge (runs on 28-31, only executes on actual last day)
0 23 28-31 * *  www-data  php /var/www/fusionpbx/app/sola_billing/resources/scripts/cron_end_of_month.php >> /var/log/sola_billing_monthly.log 2>&1

# Daily: retry failed charges
0 8 * * *       www-data  php /var/www/fusionpbx/app/sola_billing/resources/scripts/cron_retry_failed.php >> /var/log/sola_billing_retry.log 2>&1
```

### 5. Configure Sola/Cardknox API keys

Navigate to **Sola Billing → Settings** in the FusionPBX admin panel and enter your API keys. Start with sandbox mode enabled.

## Billing Flow

### Monthly cycle

1. **Hourly** (`cron_intl_cdr_scan.php`): Scans `v_xml_cdr` for outbound international calls. Detects international numbers (starts with `011` or `+`, excluding NANP). Looks up rates via longest-prefix match. Writes charge records as `pending` in `v_sola_call_charges`.

2. **End of month** (`cron_end_of_month.php`): For each enabled domain:
   - Counts active extensions × rate/extension = subscription amount
   - Sums all pending international call charges
   - Generates a PDF invoice
   - Fires one consolidated `cc:Sale` via Sola API
   - On approve: records transaction, marks intl charges as `charged`, sends receipt email
   - On decline: restricts outbound calls immediately, schedules retry, emails admin

3. **Daily** (`cron_retry_failed.php`): Re-attempts failed charges for domains whose `next_retry_at` has passed. On success, lifts restriction. After max retries exhausted, marks as abandoned and alerts admin.

### Call restriction

When a charge fails, `FailureHandler` writes `max_outbound_calls = 0` to `v_domain_settings`. FreeSWITCH reads this immediately — no restart required. The restriction lifts automatically on successful charge or manual admin override.

### International call detection

A number is international if:
- It starts with `011` or `+`
- AND it does NOT match NANP (country code 1 + valid 10-digit US/CA number)

Rate lookup uses longest-prefix matching: strips the `011`/`+`, then tries progressively shorter prefixes until a match is found in `v_sola_intl_rate_table`. If no match, a configurable default rate is used.

## Admin Pages

| Page | Description |
|------|-------------|
| **Dashboard** | Revenue stats (month/YTD), active domains, failed charge alerts, recent transactions |
| **Domain Billing** | All domains with billing status, extension count, rate, card on file |
| **Domain Billing Edit** | Per-domain config: enable/disable, rate, intl billing, add/manage cards via iFields, test charge |
| **Rate Table** | View/add/edit/delete international rates. Toggle enabled/disabled per prefix |
| **Rate Table Import** | Upload CSV, preview parsed rows, confirm import with duplicate handling |
| **Transactions** | Filterable list of all charges/refunds/voids with pagination |
| **Transaction Detail** | Full record with void/refund actions and linked call charges |
| **Call Charges** | International call charge records (pending/charged/waived) |
| **Failed Charges** | Failed charge queue with Retry, Waive, Lift Restriction, Update Card actions |
| **Reports** | Revenue by domain with bar chart, monthly summary, CSV export |
| **Settings** | API keys, sandbox mode, default rates, retry config, email alerts |

## Sola/Cardknox API Integration

The plugin uses two Cardknox APIs:

- **Transaction API v5** (`https://x1.cardknox.com/gatewayjson`): `cc:Sale`, `cc:Save`, `cc:AuthOnly`, `cc:Void`, `cc:Refund`
- **iFields JS** (`https://cdn.cardknox.com/ifields/`): Browser-side PCI-compliant card entry that returns a single-use token

Card flow: iFields JS in browser → single-use token → `cc:Save` → permanent token stored in `v_sola_payment_methods` → used for `cc:Sale` charges.

## Webhook

Configure the webhook URL in your Sola/Cardknox portal:
```
https://your-fusionpbx.example.com/app/sola_billing/webhooks/sola_webhook.php
```

Currently handles: batch settlement notifications and chargeback/dispute alerts (emails admin).
