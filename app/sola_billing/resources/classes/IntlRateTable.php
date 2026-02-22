<?php
/**
 * IntlRateTable — International call detection and longest-prefix rate matching.
 *
 * Detection rules:
 *   International = destination starts with '011' or '+'
 *   AND does NOT match NANP pattern (+1 or 0111 + valid US/CA 10-digit number)
 *
 * Longest prefix match:
 *   Strips 011/+ prefix, then tries progressively shorter prefixes until a match.
 */
class IntlRateTable {

    private BillingDatabase $db;
    private float $default_rate;

    public function __construct(BillingDatabase $db, float $default_rate = 0.10) {
        $this->db = $db;
        $this->default_rate = $default_rate;
    }

    /**
     * Check if a destination number is international.
     *
     * @param string $destination The destination number from CDR
     * @return bool True if international
     */
    public function isInternational(string $destination): bool {
        $destination = trim($destination);

        // Must start with 011 or +
        if (!preg_match('/^(011|\+)/', $destination)) {
            return false;
        }

        // Check if it's NANP (US/Canada) — NOT international
        if ($this->isNanp($destination)) {
            return false;
        }

        return true;
    }

    /**
     * Check if a number matches the NANP pattern (US/Canada/Caribbean).
     *
     * NANP: +1 or 0111 followed by a valid 10-digit number.
     * Area codes: 2xx-9xx (first digit 2-9).
     */
    public function isNanp(string $destination): bool {
        $destination = trim($destination);

        // Normalize: strip leading + or 011
        $normalized = $destination;
        if (str_starts_with($normalized, '+')) {
            $normalized = substr($normalized, 1);
        } elseif (str_starts_with($normalized, '011')) {
            $normalized = substr($normalized, 3);
        }

        // NANP starts with country code 1
        if (!str_starts_with($normalized, '1')) {
            return false;
        }

        // Remove country code
        $number = substr($normalized, 1);

        // Must be exactly 10 digits
        if (!preg_match('/^\d{10}$/', $number)) {
            return false;
        }

        // Area code first digit must be 2-9
        $area_first = (int) $number[0];
        if ($area_first < 2 || $area_first > 9) {
            return false;
        }

        return true;
    }

    /**
     * Extract the dial prefix from an international number.
     * Strips 011 or + prefix, returns just the digits.
     */
    public function extractDigits(string $destination): string {
        $destination = trim($destination);

        if (str_starts_with($destination, '011')) {
            return substr($destination, 3);
        }
        if (str_starts_with($destination, '+')) {
            return substr($destination, 1);
        }

        return $destination;
    }

    /**
     * Find the rate for a destination using longest-prefix matching.
     *
     * @param string $destination The full destination number
     * @return array Rate info: [rate_uuid, dial_prefix, country_name, rate_per_minute, minimum_seconds]
     *               or default rate if no match found
     */
    public function findRate(string $destination): array {
        $digits = $this->extractDigits($destination);

        // Try progressively shorter prefixes (longest match first)
        $max_prefix_len = min(strlen($digits), 15);
        for ($len = $max_prefix_len; $len >= 1; $len--) {
            $prefix = substr($digits, 0, $len);
            $rate = $this->db->getRateByPrefix($prefix);
            if ($rate) {
                return [
                    'rate_uuid'       => $rate['rate_uuid'],
                    'dial_prefix'     => $rate['dial_prefix'],
                    'country_name'    => $rate['country_name'],
                    'rate_per_minute' => (float) $rate['rate_per_minute'],
                    'minimum_seconds' => (int) ($rate['minimum_seconds'] ?? 6),
                    'matched'         => true,
                ];
            }
        }

        // No match — return default rate
        return [
            'rate_uuid'       => null,
            'dial_prefix'     => substr($digits, 0, 3),
            'country_name'    => 'Unknown Destination',
            'rate_per_minute' => $this->default_rate,
            'minimum_seconds' => 6,
            'matched'         => false,
        ];
    }

    /**
     * Calculate the charge for a call.
     *
     * @param int $billsec Billed seconds from CDR
     * @param float $rate_per_minute Rate per minute
     * @param int $minimum_seconds Minimum billing increment
     * @return array [charge_amount (precise), charge_amount_rounded (to cents)]
     */
    public function calculateCharge(int $billsec, float $rate_per_minute, int $minimum_seconds = 6): array {
        // Apply minimum seconds
        $billed_seconds = max($billsec, $minimum_seconds);

        // Calculate: billsec / 60 * rate_per_minute
        $charge = ($billed_seconds / 60) * $rate_per_minute;

        return [
            'charge_amount'         => round($charge, 4),
            'charge_amount_rounded' => round($charge, 2),
        ];
    }

    /**
     * Import rates from CSV data.
     *
     * Expected CSV format: country_name, country_code, dial_prefix, rate_per_minute, minimum_seconds
     *
     * @param string $csv_content Raw CSV content
     * @param string $duplicate_action 'update' or 'skip'
     * @return array Import results: [added, updated, skipped, errors]
     */
    public function importCsv(string $csv_content, string $duplicate_action = 'update'): array {
        $results = ['added' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

        $lines = explode("\n", trim($csv_content));

        foreach ($lines as $line_num => $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Skip header row if present
            if ($line_num === 0 && stripos($line, 'country_name') !== false) {
                continue;
            }

            $fields = str_getcsv($line);
            if (count($fields) < 4) {
                $results['errors'][] = "Line " . ($line_num + 1) . ": insufficient columns";
                continue;
            }

            $country_name    = trim($fields[0]);
            $country_code    = trim($fields[1] ?? '');
            $dial_prefix     = trim($fields[2]);
            $rate_per_minute = trim($fields[3]);
            $minimum_seconds = trim($fields[4] ?? '6');

            // Validate
            if (empty($country_name) || empty($dial_prefix)) {
                $results['errors'][] = "Line " . ($line_num + 1) . ": missing country name or dial prefix";
                continue;
            }
            if (!is_numeric($rate_per_minute) || (float) $rate_per_minute < 0) {
                $results['errors'][] = "Line " . ($line_num + 1) . ": invalid rate '{$rate_per_minute}'";
                continue;
            }

            // Strip any leading + or 011 from prefix
            $dial_prefix = ltrim($dial_prefix, '+');
            if (str_starts_with($dial_prefix, '011')) {
                $dial_prefix = substr($dial_prefix, 3);
            }

            // Check for existing prefix
            $existing = $this->db->getRateByPrefix($dial_prefix);

            if ($existing) {
                if ($duplicate_action === 'update') {
                    $this->db->saveRate([
                        'country_name'    => $country_name,
                        'country_code'    => $country_code,
                        'rate_per_minute' => (float) $rate_per_minute,
                        'minimum_seconds' => (int) $minimum_seconds,
                    ], $existing['rate_uuid']);
                    $results['updated']++;
                } else {
                    $results['skipped']++;
                }
            } else {
                $this->db->saveRate([
                    'country_name'    => $country_name,
                    'country_code'    => $country_code,
                    'dial_prefix'     => $dial_prefix,
                    'rate_per_minute' => (float) $rate_per_minute,
                    'minimum_seconds' => (int) $minimum_seconds,
                    'enabled'         => 'true',
                ]);
                $results['added']++;
            }
        }

        return $results;
    }

    /**
     * Parse CSV content and return preview rows (for admin preview before import).
     */
    public function previewCsv(string $csv_content, int $max_rows = 20): array {
        $rows = [];
        $lines = explode("\n", trim($csv_content));

        foreach ($lines as $line_num => $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $fields = str_getcsv($line);
            $is_header = ($line_num === 0 && stripos($line, 'country_name') !== false);

            $rows[] = [
                'line'            => $line_num + 1,
                'country_name'    => trim($fields[0] ?? ''),
                'country_code'    => trim($fields[1] ?? ''),
                'dial_prefix'     => trim($fields[2] ?? ''),
                'rate_per_minute' => trim($fields[3] ?? ''),
                'minimum_seconds' => trim($fields[4] ?? '6'),
                'is_header'       => $is_header,
            ];

            if (count($rows) >= $max_rows + 1) break; // +1 for possible header
        }

        return $rows;
    }
}
