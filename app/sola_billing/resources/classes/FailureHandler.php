<?php
/**
 * FailureHandler — Decline & Restriction Logic
 *
 * When a charge fails, outbound calls are blocked immediately via
 * FusionPBX's native v_domain_settings limit system (max_outbound_calls = 0).
 * FreeSWITCH reads this and enforces it immediately — no restart required.
 *
 * Restriction lifts automatically when a charge succeeds.
 * Admin can also lift manually (with reason logged to audit trail).
 */
class FailureHandler {

    private BillingDatabase $db;
    private $pg_db;

    public function __construct(BillingDatabase $billing_db) {
        $this->db = $billing_db;
        $this->pg_db = new database;
    }

    /**
     * Apply outbound call restriction for a domain.
     *
     * Sets max_outbound_calls = 0 in v_domain_settings, which FreeSWITCH
     * reads and enforces immediately.
     */
    public function applyRestriction(string $domain_uuid): void {
        // Insert or update v_domain_settings to block outbound calls
        $sql = "INSERT INTO v_domain_settings
                    (domain_setting_uuid, domain_uuid, domain_setting_category,
                     domain_setting_subcategory, domain_setting_name, domain_setting_value,
                     domain_setting_enabled)
                VALUES
                    (gen_random_uuid(), :domain_uuid, 'limit', 'max_outbound_calls',
                     'numeric', '0', 'true')
                ON CONFLICT (domain_uuid, domain_setting_category, domain_setting_subcategory)
                DO UPDATE SET
                    domain_setting_value = '0',
                    domain_setting_enabled = 'true'";

        $this->pg_db->execute($sql, [':domain_uuid' => $domain_uuid]);

        // Update our tracking record
        $this->db->saveDomainBilling($domain_uuid, [
            'calls_restricted'       => 'true',
            'restriction_started_at' => date('Y-m-d H:i:s'),
        ]);

        // Audit log
        $this->db->writeAuditLog(
            $domain_uuid,
            'system',
            'restriction_applied',
            ['reason' => 'charge_failed']
        );
    }

    /**
     * Lift outbound call restriction for a domain.
     *
     * Disables the max_outbound_calls limit, restoring normal calling.
     */
    public function liftRestriction(string $domain_uuid, string $actor = 'system', string $reason = ''): void {
        $sql = "UPDATE v_domain_settings
                SET domain_setting_value = '',
                    domain_setting_enabled = 'false'
                WHERE domain_uuid = :domain_uuid
                  AND domain_setting_category = 'limit'
                  AND domain_setting_subcategory = 'max_outbound_calls'";

        $this->pg_db->execute($sql, [':domain_uuid' => $domain_uuid]);

        // Update our tracking record
        $this->db->saveDomainBilling($domain_uuid, [
            'calls_restricted'       => 'false',
            'restriction_started_at' => null,
        ]);

        // Audit log
        $this->db->writeAuditLog(
            $domain_uuid,
            $actor,
            'restriction_lifted',
            ['reason' => $reason ?: 'charge_succeeded']
        );
    }

    /**
     * Handle a failed charge for a domain.
     *
     * - Apply restriction
     * - Increment retry count
     * - Schedule next retry
     * - Send alert email
     */
    public function handleFailure(string $domain_uuid, string $error_message = ''): void {
        $billing = $this->db->getDomainBilling($domain_uuid);
        $retry_count = (int) ($billing['retry_count'] ?? 0) + 1;
        $retry_interval = (int) ($this->db->getSetting('retry_interval_days') ?? 3);
        $max_retries = (int) ($this->db->getSetting('max_retry_attempts') ?? 3);

        // Apply restriction immediately
        $this->applyRestriction($domain_uuid);

        if ($retry_count <= $max_retries) {
            // Schedule next retry
            $next_retry = date('Y-m-d H:i:s', strtotime("+{$retry_interval} days"));
            $this->db->saveDomainBilling($domain_uuid, [
                'last_charge_result' => 'D',
                'retry_count'        => $retry_count,
                'next_retry_at'      => $next_retry,
            ]);

            // Send failure email
            $this->sendEmail(
                $domain_uuid,
                "Billing FAILED — Retry {$retry_count}/{$max_retries}",
                $error_message
            );
        } else {
            // Exhausted all retries
            $this->db->saveDomainBilling($domain_uuid, [
                'last_charge_result' => 'D',
                'retry_count'        => $retry_count,
                'next_retry_at'      => null,
            ]);

            $this->db->writeAuditLog(
                $domain_uuid,
                'system',
                'retries_exhausted',
                ['retry_count' => $retry_count, 'error' => $error_message]
            );

            // Send final failure email
            $this->sendEmail(
                $domain_uuid,
                "FINAL FAILURE — Manual Action Required",
                "All {$max_retries} retry attempts exhausted. Domain remains restricted.\n" .
                "Last error: {$error_message}\n\n" .
                "Admin must manually update the card and trigger a charge, or lift the restriction."
            );
        }
    }

    /**
     * Handle a successful charge for a domain.
     *
     * - Lift restriction if active
     * - Reset retry count
     * - Send success email
     */
    public function handleSuccess(string $domain_uuid, float $amount): void {
        $billing = $this->db->getDomainBilling($domain_uuid);

        // Lift restriction if it was applied
        $restricted = ($billing['calls_restricted'] ?? '') === 't' ||
                     ($billing['calls_restricted'] ?? '') === 'true';
        if ($restricted) {
            $this->liftRestriction($domain_uuid, 'system', 'charge_succeeded');
        }

        // Reset retry state
        $this->db->saveDomainBilling($domain_uuid, [
            'last_charge_date'   => date('Y-m-d'),
            'last_charge_result' => 'A',
            'retry_count'        => 0,
            'next_retry_at'      => null,
        ]);

        // Send success email
        $this->sendEmail(
            $domain_uuid,
            "Billing Successful — $" . number_format($amount, 2),
            ''
        );
    }

    /**
     * Check if a domain is eligible for retry.
     */
    public function isRetryEligible(string $domain_uuid): bool {
        $billing = $this->db->getDomainBilling($domain_uuid);
        if (!$billing) return false;

        $max_retries = (int) ($this->db->getSetting('max_retry_attempts') ?? 3);
        $retry_count = (int) ($billing['retry_count'] ?? 0);

        if ($retry_count > $max_retries) return false;

        $next_retry = $billing['next_retry_at'] ?? null;
        if ($next_retry && strtotime($next_retry) > time()) return false;

        return true;
    }

    /**
     * Get all domains due for retry.
     */
    public function getDomainsForRetry(): array {
        $sql = "SELECT b.*, d.domain_name
                FROM v_sola_domain_billing b
                JOIN v_domains d ON d.domain_uuid = b.domain_uuid
                WHERE b.billing_enabled = TRUE
                  AND b.last_charge_result IN ('D', 'E')
                  AND b.next_retry_at IS NOT NULL
                  AND b.next_retry_at <= NOW()
                  AND b.retry_count <= :max_retries";

        $max_retries = (int) ($this->db->getSetting('max_retry_attempts') ?? 3);
        $this->pg_db->execute($sql, [':max_retries' => $max_retries]);
        return $this->pg_db->result() ?: [];
    }

    /**
     * Send an email notification to admin.
     */
    private function sendEmail(string $domain_uuid, string $subject_suffix, string $extra_detail): void {
        $admin_email = $this->db->getSetting('admin_email');
        if (empty($admin_email)) return;

        // Get domain name
        $this->pg_db->execute(
            "SELECT domain_name FROM v_domains WHERE domain_uuid = :uuid",
            [':uuid' => $domain_uuid]
        );
        $rows = $this->pg_db->result();
        $domain_name = $rows[0]['domain_name'] ?? $domain_uuid;

        $subject = "Sola Billing: {$domain_name} — {$subject_suffix}";
        $body = "Domain: {$domain_name}\n";
        $body .= "Date: " . date('Y-m-d H:i:s') . "\n";
        if (!empty($extra_detail)) {
            $body .= "\n{$extra_detail}\n";
        }
        $body .= "\n— FusionPBX Sola Billing System";

        $emails = array_map('trim', explode(',', $admin_email));
        foreach ($emails as $email) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                @mail($email, $subject, $body, "From: noreply@" . gethostname());
            }
        }
    }
}
