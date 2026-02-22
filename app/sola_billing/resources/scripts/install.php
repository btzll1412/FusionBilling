<?php
/**
 * install.php — Database migration for Sola Billing plugin
 *
 * Creates all required tables in the existing FusionPBX PostgreSQL database.
 * Safe to re-run: uses IF NOT EXISTS for all objects.
 *
 * Usage: sudo -u www-data php /var/www/fusionpbx/app/sola_billing/resources/scripts/install.php
 */

// Bootstrap FusionPBX
$document_root = realpath(dirname(__FILE__) . '/../../../..');
require_once $document_root . '/resources/require.php';
require_once 'resources/classes/database.php';

$database = new database;

echo "Sola Billing — Database Migration\n";
echo "==================================\n\n";

$sql_statements = [];

// ─── Per-domain billing configuration ────────────────────────────────────
$sql_statements[] = "
CREATE TABLE IF NOT EXISTS v_sola_domain_billing (
    billing_uuid            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    domain_uuid             UUID NOT NULL UNIQUE REFERENCES v_domains(domain_uuid),

    -- Subscription settings (per-extension × rate)
    billing_enabled         BOOLEAN DEFAULT FALSE,
    rate_per_extension      NUMERIC(8,2) DEFAULT 0.00,

    -- International call settings
    intl_billing_enabled    BOOLEAN DEFAULT FALSE,

    -- Payment method on file
    default_method_uuid     UUID,

    -- Failure handling
    restrict_on_failure     BOOLEAN DEFAULT TRUE,
    calls_restricted        BOOLEAN DEFAULT FALSE,
    restriction_started_at  TIMESTAMP,
    retry_count             SMALLINT DEFAULT 0,
    next_retry_at           TIMESTAMP,
    last_charge_date        DATE,
    last_charge_result      VARCHAR(1),

    created_at              TIMESTAMP DEFAULT NOW(),
    updated_at              TIMESTAMP DEFAULT NOW()
)";

// ─── Saved payment methods (cards on file) per domain ────────────────────
$sql_statements[] = "
CREATE TABLE IF NOT EXISTS v_sola_payment_methods (
    method_uuid         UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    domain_uuid         UUID NOT NULL REFERENCES v_domains(domain_uuid),
    sola_token          VARCHAR(128) NOT NULL,
    token_type          VARCHAR(8) DEFAULT 'cc',
    card_type           VARCHAR(32),
    masked_card         VARCHAR(20),
    exp_date            VARCHAR(4),
    cardholder_name     VARCHAR(128),
    is_default          BOOLEAN DEFAULT FALSE,
    nickname            VARCHAR(64),
    created_by          VARCHAR(128),
    created_at          TIMESTAMP DEFAULT NOW()
)";

// ─── All charge transactions ─────────────────────────────────────────────
$sql_statements[] = "
CREATE TABLE IF NOT EXISTS v_sola_transactions (
    transaction_uuid    UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    domain_uuid         UUID NOT NULL REFERENCES v_domains(domain_uuid),
    method_uuid         UUID REFERENCES v_sola_payment_methods(method_uuid),
    charge_type         VARCHAR(24) NOT NULL,
    xref_num            BIGINT,
    x_invoice           VARCHAR(64),
    amount              NUMERIC(10,2) NOT NULL,
    extension_count     SMALLINT,
    billing_period_start DATE,
    billing_period_end   DATE,
    result              VARCHAR(1),
    result_message      VARCHAR(255),
    auth_code           VARCHAR(32),
    parent_xref_num     BIGINT,
    charged_by          VARCHAR(128) DEFAULT 'system',
    ip_address          INET,
    created_at          TIMESTAMP DEFAULT NOW()
)";

// ─── International rate table ────────────────────────────────────────────
$sql_statements[] = "
CREATE TABLE IF NOT EXISTS v_sola_intl_rate_table (
    rate_uuid           UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    country_name        VARCHAR(128) NOT NULL,
    country_code        VARCHAR(8),
    dial_prefix         VARCHAR(16) NOT NULL,
    rate_per_minute     NUMERIC(8,4) NOT NULL,
    minimum_seconds     SMALLINT DEFAULT 6,
    enabled             BOOLEAN DEFAULT TRUE,
    notes               TEXT,
    created_at          TIMESTAMP DEFAULT NOW(),
    updated_at          TIMESTAMP DEFAULT NOW()
)";

// ─── Individual international call charges ───────────────────────────────
$sql_statements[] = "
CREATE TABLE IF NOT EXISTS v_sola_call_charges (
    call_charge_uuid    UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    domain_uuid         UUID NOT NULL REFERENCES v_domains(domain_uuid),
    xml_cdr_uuid        UUID,
    destination_number  VARCHAR(32),
    dial_prefix         VARCHAR(16),
    country_name        VARCHAR(128),
    billsec             INT,
    rate_per_minute     NUMERIC(8,4),
    charge_amount       NUMERIC(10,4),
    charge_amount_rounded NUMERIC(10,2),
    call_date           TIMESTAMP,
    status              VARCHAR(16) DEFAULT 'pending',
    transaction_uuid    UUID REFERENCES v_sola_transactions(transaction_uuid),
    created_at          TIMESTAMP DEFAULT NOW()
)";

// ─── Audit log ───────────────────────────────────────────────────────────
$sql_statements[] = "
CREATE TABLE IF NOT EXISTS v_sola_audit_log (
    log_uuid        UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    domain_uuid     UUID REFERENCES v_domains(domain_uuid),
    actor           VARCHAR(128),
    action          VARCHAR(64),
    detail          JSONB,
    ip_address      INET,
    created_at      TIMESTAMP DEFAULT NOW()
)";

// Execute all CREATE TABLE statements
$errors = 0;
foreach ($sql_statements as $i => $sql) {
    echo "Running statement " . ($i + 1) . " of " . count($sql_statements) . "... ";
    try {
        $database->execute($sql);
        echo "OK\n";
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
        $errors++;
    }
}

// ─── Create indexes (separate so table creation can succeed independently) ──
$index_statements = [
    "CREATE UNIQUE INDEX IF NOT EXISTS idx_sola_rate_prefix
        ON v_sola_intl_rate_table(dial_prefix) WHERE enabled = TRUE",
    "CREATE INDEX IF NOT EXISTS idx_sola_call_charges_domain
        ON v_sola_call_charges(domain_uuid, status)",
    "CREATE INDEX IF NOT EXISTS idx_sola_call_charges_cdr
        ON v_sola_call_charges(xml_cdr_uuid)",
    "CREATE INDEX IF NOT EXISTS idx_sola_transactions_domain
        ON v_sola_transactions(domain_uuid, created_at)",
    "CREATE INDEX IF NOT EXISTS idx_sola_audit_log_domain
        ON v_sola_audit_log(domain_uuid, created_at)",
    "CREATE INDEX IF NOT EXISTS idx_sola_payment_methods_domain
        ON v_sola_payment_methods(domain_uuid)",
];

echo "\nCreating indexes...\n";
foreach ($index_statements as $i => $sql) {
    echo "Index " . ($i + 1) . " of " . count($index_statements) . "... ";
    try {
        $database->execute($sql);
        echo "OK\n";
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
        $errors++;
    }
}

echo "\n==================================\n";
if ($errors === 0) {
    echo "Migration completed successfully.\n";
} else {
    echo "Migration completed with {$errors} error(s). Review output above.\n";
}
