<?php
/**
 * CdrScanner — Reads v_xml_cdr, identifies international calls,
 * calculates charges, and writes to v_sola_call_charges.
 *
 * Runs hourly via cron. Only processes CDRs that:
 * - Are outbound
 * - Have billsec > 0 (answered)
 * - Haven't already been processed
 * - Belong to a domain with intl_billing_enabled = TRUE
 */
class CdrScanner {

    private BillingDatabase $db;
    private IntlRateTable $rate_table;

    public function __construct(BillingDatabase $db, IntlRateTable $rate_table) {
        $this->db = $db;
        $this->rate_table = $rate_table;
    }

    /**
     * Scan for new international CDRs and create call charge records.
     *
     * @param string $since Only scan CDRs from this timestamp forward (ISO 8601)
     * @return array Summary: [scanned, matched, charged, skipped, errors]
     */
    public function scan(string $since = null): array {
        $results = [
            'scanned' => 0,
            'matched' => 0,
            'charged' => 0,
            'skipped' => 0,
            'errors'  => [],
        ];

        // Default: scan CDRs from 25 hours ago (overlap to catch any missed)
        if ($since === null) {
            $since = date('Y-m-d H:i:s', strtotime('-25 hours'));
        }

        // Get domains with international billing enabled
        $enabled_domains = $this->getIntlEnabledDomains();
        if (empty($enabled_domains)) {
            return $results;
        }

        // Fetch unbilled outbound CDRs
        $cdrs = $this->db->getUnbilledOutboundCdrs($since);
        $results['scanned'] = count($cdrs);

        foreach ($cdrs as $cdr) {
            $domain_uuid = $cdr['domain_uuid'] ?? '';
            $destination = $cdr['destination_number'] ?? '';
            $billsec = (int) ($cdr['billsec'] ?? 0);
            $xml_cdr_uuid = $cdr['xml_cdr_uuid'] ?? '';

            // Skip if domain doesn't have intl billing enabled
            if (!isset($enabled_domains[$domain_uuid])) {
                $results['skipped']++;
                continue;
            }

            // Skip if not international
            if (!$this->rate_table->isInternational($destination)) {
                $results['skipped']++;
                continue;
            }

            $results['matched']++;

            // Skip if already processed (double-check)
            if ($this->db->isCallChargeExists($xml_cdr_uuid)) {
                $results['skipped']++;
                continue;
            }

            // Look up rate
            $rate_info = $this->rate_table->findRate($destination);

            // Calculate charge
            $charge = $this->rate_table->calculateCharge(
                $billsec,
                $rate_info['rate_per_minute'],
                $rate_info['minimum_seconds']
            );

            // Create call charge record
            try {
                $this->db->createCallCharge([
                    'domain_uuid'           => $domain_uuid,
                    'xml_cdr_uuid'          => $xml_cdr_uuid,
                    'destination_number'    => $destination,
                    'dial_prefix'           => $rate_info['dial_prefix'],
                    'country_name'          => $rate_info['country_name'],
                    'billsec'               => $billsec,
                    'rate_per_minute'       => $rate_info['rate_per_minute'],
                    'charge_amount'         => $charge['charge_amount'],
                    'charge_amount_rounded' => $charge['charge_amount_rounded'],
                    'call_date'             => $cdr['start_stamp'] ?? date('Y-m-d H:i:s'),
                    'status'                => 'pending',
                ]);
                $results['charged']++;
            } catch (Exception $e) {
                $results['errors'][] = "CDR {$xml_cdr_uuid}: " . $e->getMessage();
            }
        }

        // Audit log
        $this->db->writeAuditLog(
            null,
            'cron',
            'cdr_scan_completed',
            $results
        );

        return $results;
    }

    /**
     * Get a map of domain_uuids that have international billing enabled.
     */
    private function getIntlEnabledDomains(): array {
        $domains = $this->db->getEnabledDomains();
        $map = [];
        foreach ($domains as $d) {
            $intl = $d['intl_billing_enabled'] ?? '';
            if ($intl === true || $intl === 't' || $intl === 'true') {
                $map[$d['domain_uuid']] = true;
            }
        }
        return $map;
    }
}
