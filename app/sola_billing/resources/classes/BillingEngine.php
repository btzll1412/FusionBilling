<?php
/**
 * BillingEngine — Core end-of-month billing logic.
 *
 * Called by cron_end_of_month.php on the last day of each month at 11 PM.
 * Can also be triggered manually per domain from the admin panel.
 *
 * For each enabled domain:
 * 1. Calculate subscription line item (extensions × rate)
 * 2. Calculate international line items (pending call charges)
 * 3. Build invoice (PDF)
 * 4. Charge the card (one consolidated charge)
 * 5. Handle result (approve → record + clear | decline → restrict + retry)
 */
class BillingEngine {

    private BillingDatabase $db;
    private SolaGateway $gateway;
    private FailureHandler $failure_handler;
    private ?InvoiceGenerator $invoice_gen;

    public function __construct(
        BillingDatabase $db,
        SolaGateway $gateway,
        FailureHandler $failure_handler,
        ?InvoiceGenerator $invoice_gen = null
    ) {
        $this->db = $db;
        $this->gateway = $gateway;
        $this->failure_handler = $failure_handler;
        $this->invoice_gen = $invoice_gen;
    }

    /**
     * Run end-of-month billing for all enabled domains (or a specific one).
     *
     * @param string|null $domain_uuid If provided, only bill this domain
     * @return array Results per domain
     */
    public function runEndOfMonthBilling(?string $domain_uuid = null): array {
        $results = [];

        if ($domain_uuid) {
            // Single domain
            $billing = $this->db->getDomainBilling($domain_uuid);
            if (!$billing) {
                return [['domain_uuid' => $domain_uuid, 'status' => 'error', 'message' => 'Billing not configured']];
            }
            $domains = [$billing];
            // Get domain_name
            $pg = new database;
            $pg->execute("SELECT domain_name FROM v_domains WHERE domain_uuid = :uuid", [':uuid' => $domain_uuid]);
            $rows = $pg->result();
            if (!empty($rows)) {
                $domains[0]['domain_name'] = $rows[0]['domain_name'];
            }
        } else {
            // All enabled domains
            $domains = $this->db->getEnabledDomains();
        }

        foreach ($domains as $domain) {
            $d_uuid = $domain['domain_uuid'];
            $d_name = $domain['domain_name'] ?? $d_uuid;

            // Prevent double-billing: skip if already charged this month
            $last_charge = $domain['last_charge_date'] ?? '';
            $first_of_month = date('Y-m-01');
            if (!empty($last_charge) && $last_charge >= $first_of_month && ($domain['last_charge_result'] ?? '') === 'A') {
                $results[] = [
                    'domain_uuid' => $d_uuid,
                    'domain_name' => $d_name,
                    'status'      => 'skipped',
                    'message'     => 'Already billed this month',
                ];
                continue;
            }

            $result = $this->billDomain($domain);
            $results[] = $result;
        }

        return $results;
    }

    /**
     * Bill a single domain.
     */
    private function billDomain(array $domain): array {
        $d_uuid = $domain['domain_uuid'];
        $d_name = $domain['domain_name'] ?? $d_uuid;
        $rate = (float) ($domain['rate_per_extension'] ?? 0);

        // ── STEP 1: Subscription line item ──
        $ext_count = $this->db->countActiveExtensions($d_uuid);
        $subscription_amount = $ext_count * $rate;

        // ── STEP 2: International line items ──
        $intl_enabled = ($domain['intl_billing_enabled'] ?? '') === 't' ||
                       ($domain['intl_billing_enabled'] ?? '') === 'true';
        $intl_total = 0.00;
        $pending_charges = [];

        if ($intl_enabled) {
            $intl_total = $this->db->getPendingCallChargesTotal($d_uuid);
            if ($intl_total > 0) {
                $pending_charges = $this->db->getPendingCallCharges($d_uuid);
            }
        }

        // ── STEP 3: Combine ──
        $invoice_total = round($subscription_amount + $intl_total, 2);

        if ($invoice_total <= 0) {
            $this->db->writeAuditLog($d_uuid, 'system', 'nothing_to_bill', [
                'extension_count'     => $ext_count,
                'subscription_amount' => $subscription_amount,
                'intl_total'          => $intl_total,
            ]);
            return [
                'domain_uuid' => $d_uuid,
                'domain_name' => $d_name,
                'status'      => 'skipped',
                'message'     => 'Nothing to bill ($0.00)',
            ];
        }

        // ── STEP 3b: Generate invoice number ──
        $domain_short = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $d_name), 0, 12));
        $period = date('Ym');
        $invoice_number = "INV-{$domain_short}-{$period}";

        // ── STEP 3c: Generate PDF invoice ──
        $invoice_path = null;
        if ($this->invoice_gen) {
            try {
                $invoice_data = [
                    'invoice_number'      => $invoice_number,
                    'domain_name'         => $d_name,
                    'extension_count'     => $ext_count,
                    'rate_per_extension'  => $rate,
                    'subscription_amount' => $subscription_amount,
                    'intl_charges'        => $pending_charges,
                    'intl_total'          => $intl_total,
                    'invoice_total'       => $invoice_total,
                    'billing_period'      => date('F Y'),
                ];
                $invoice_path = $this->invoice_gen->generate($d_uuid, $invoice_data);
            } catch (Exception $e) {
                // Invoice generation failure should not block the charge
                $this->db->writeAuditLog($d_uuid, 'system', 'invoice_generation_failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // ── STEP 4: Get payment method and charge ──
        $method = $this->db->getDefaultPaymentMethod($d_uuid);
        if (!$method) {
            $this->db->writeAuditLog($d_uuid, 'system', 'no_payment_method', []);
            return [
                'domain_uuid' => $d_uuid,
                'domain_name' => $d_name,
                'status'      => 'error',
                'message'     => 'No payment method on file',
            ];
        }

        $description = "FusionPBX — {$d_name} — {$ext_count} ext";
        if ($intl_total > 0) {
            $description .= " + intl calls";
        }

        $response = $this->gateway->chargeToken(
            $method['sola_token'],
            $invoice_total,
            $invoice_number,
            $description,
            '' // no IP for cron
        );

        // ── STEP 5: Handle result ──
        $billing_period_start = date('Y-m-01');
        $billing_period_end = date('Y-m-t');

        if ($this->gateway->isApproved($response)) {
            // SUCCESS
            $transaction_uuid = $this->db->createTransaction([
                'domain_uuid'        => $d_uuid,
                'method_uuid'        => $method['method_uuid'],
                'charge_type'        => 'monthly_invoice',
                'xref_num'           => $this->gateway->getRefNum($response),
                'x_invoice'          => $invoice_number,
                'amount'             => $invoice_total,
                'extension_count'    => $ext_count,
                'billing_period_start' => $billing_period_start,
                'billing_period_end'   => $billing_period_end,
                'result'             => 'A',
                'result_message'     => $response['xStatus'] ?? 'Approved',
                'auth_code'          => $this->gateway->getAuthCode($response),
                'charged_by'         => 'system',
            ]);

            // Mark pending intl charges as charged
            if (!empty($pending_charges)) {
                $this->db->markCallChargesCharged($d_uuid, $transaction_uuid);
            }

            // Handle success (lift restriction, reset retries, email)
            $this->failure_handler->handleSuccess($d_uuid, $invoice_total);

            $this->db->writeAuditLog($d_uuid, 'system', 'charge_approved', [
                'transaction_uuid' => $transaction_uuid,
                'amount'           => $invoice_total,
                'extensions'       => $ext_count,
                'intl_total'       => $intl_total,
                'invoice'          => $invoice_number,
            ]);

            return [
                'domain_uuid' => $d_uuid,
                'domain_name' => $d_name,
                'status'      => 'approved',
                'amount'      => $invoice_total,
                'invoice'     => $invoice_number,
                'xref_num'    => $this->gateway->getRefNum($response),
            ];

        } else {
            // DECLINED / ERROR
            $result_code = $response['xResult'] ?? 'E';
            $error_msg = $this->gateway->getErrorMessage($response);

            $this->db->createTransaction([
                'domain_uuid'        => $d_uuid,
                'method_uuid'        => $method['method_uuid'],
                'charge_type'        => 'monthly_invoice',
                'xref_num'           => $this->gateway->getRefNum($response),
                'x_invoice'          => $invoice_number,
                'amount'             => $invoice_total,
                'extension_count'    => $ext_count,
                'billing_period_start' => $billing_period_start,
                'billing_period_end'   => $billing_period_end,
                'result'             => $result_code,
                'result_message'     => $error_msg,
                'charged_by'         => 'system',
            ]);

            // Handle failure (restrict, schedule retry, email)
            $this->failure_handler->handleFailure($d_uuid, $error_msg);

            $this->db->writeAuditLog($d_uuid, 'system', 'charge_failed', [
                'amount'  => $invoice_total,
                'result'  => $result_code,
                'error'   => $error_msg,
                'invoice' => $invoice_number,
            ]);

            return [
                'domain_uuid' => $d_uuid,
                'domain_name' => $d_name,
                'status'      => 'declined',
                'amount'      => $invoice_total,
                'error'       => $error_msg,
            ];
        }
    }

    /**
     * Retry billing for a specific failed domain.
     * Called by cron_retry_failed.php and admin "Retry Now" button.
     */
    public function retryFailedDomain(string $domain_uuid): array {
        if (!$this->failure_handler->isRetryEligible($domain_uuid)) {
            return [
                'domain_uuid' => $domain_uuid,
                'status'      => 'error',
                'message'     => 'Domain not eligible for retry (exhausted or not yet due)',
            ];
        }

        return $this->runEndOfMonthBilling($domain_uuid)[0] ?? [
            'domain_uuid' => $domain_uuid,
            'status'      => 'error',
            'message'     => 'Unexpected error during retry',
        ];
    }

    /**
     * Fire a manual charge for a domain (admin-initiated).
     */
    public function manualCharge(string $domain_uuid, float $amount, string $description, string $admin_user, string $ip = ''): array {
        $method = $this->db->getDefaultPaymentMethod($domain_uuid);
        if (!$method) {
            return ['status' => 'error', 'message' => 'No payment method on file'];
        }

        // Generate invoice number
        $pg = new database;
        $pg->execute("SELECT domain_name FROM v_domains WHERE domain_uuid = :uuid", [':uuid' => $domain_uuid]);
        $rows = $pg->result();
        $d_name = $rows[0]['domain_name'] ?? 'UNKNOWN';
        $domain_short = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $d_name), 0, 12));

        // Find next sequence number for today
        $today = date('Ymd');
        $invoice_number = "MAN-{$domain_short}-{$today}-001";

        $response = $this->gateway->chargeToken(
            $method['sola_token'],
            $amount,
            $invoice_number,
            $description,
            $ip
        );

        if ($this->gateway->isApproved($response)) {
            $transaction_uuid = $this->db->createTransaction([
                'domain_uuid'    => $domain_uuid,
                'method_uuid'    => $method['method_uuid'],
                'charge_type'    => 'manual',
                'xref_num'       => $this->gateway->getRefNum($response),
                'x_invoice'      => $invoice_number,
                'amount'         => $amount,
                'result'         => 'A',
                'result_message' => $response['xStatus'] ?? 'Approved',
                'auth_code'      => $this->gateway->getAuthCode($response),
                'charged_by'     => $admin_user,
                'ip_address'     => $ip,
            ]);

            // If domain was restricted, lift it
            $this->failure_handler->handleSuccess($domain_uuid, $amount);

            $this->db->writeAuditLog($domain_uuid, $admin_user, 'manual_charge_approved', [
                'amount'           => $amount,
                'transaction_uuid' => $transaction_uuid,
            ], $ip);

            return [
                'status'           => 'approved',
                'transaction_uuid' => $transaction_uuid,
                'xref_num'         => $this->gateway->getRefNum($response),
                'auth_code'        => $this->gateway->getAuthCode($response),
            ];
        } else {
            $error_msg = $this->gateway->getErrorMessage($response);

            $this->db->createTransaction([
                'domain_uuid'    => $domain_uuid,
                'method_uuid'    => $method['method_uuid'],
                'charge_type'    => 'manual',
                'x_invoice'      => $invoice_number,
                'amount'         => $amount,
                'result'         => $response['xResult'] ?? 'E',
                'result_message' => $error_msg,
                'charged_by'     => $admin_user,
                'ip_address'     => $ip,
            ]);

            $this->db->writeAuditLog($domain_uuid, $admin_user, 'manual_charge_failed', [
                'amount' => $amount,
                'error'  => $error_msg,
            ], $ip);

            return [
                'status'  => 'declined',
                'message' => $error_msg,
            ];
        }
    }
}
