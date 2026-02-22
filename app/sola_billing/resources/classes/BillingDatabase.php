<?php
/**
 * BillingDatabase — All database read/write operations for Sola Billing.
 *
 * Uses FusionPBX's native database class for all queries.
 */
class BillingDatabase {

    private $db;

    public function __construct() {
        $this->db = new database;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  DOMAIN BILLING CONFIG
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Get billing config for a specific domain.
     */
    public function getDomainBilling(string $domain_uuid): ?array {
        $sql = "SELECT * FROM v_sola_domain_billing WHERE domain_uuid = :domain_uuid";
        $params = [':domain_uuid' => $domain_uuid];
        $this->db->execute($sql, $params);
        $rows = $this->db->result();
        return !empty($rows) ? $rows[0] : null;
    }

    /**
     * Get all enabled domain billing configs.
     */
    public function getEnabledDomains(): array {
        $sql = "SELECT b.*, d.domain_name
                FROM v_sola_domain_billing b
                JOIN v_domains d ON d.domain_uuid = b.domain_uuid
                WHERE b.billing_enabled = TRUE
                ORDER BY d.domain_name";
        $this->db->execute($sql);
        return $this->db->result() ?: [];
    }

    /**
     * Get all domain billing configs (for admin list).
     */
    public function getAllDomainBilling(): array {
        $sql = "SELECT b.*, d.domain_name,
                    (SELECT COUNT(*) FROM v_extensions e
                     WHERE e.domain_uuid = d.domain_uuid AND e.enabled = 'true') AS extension_count,
                    (SELECT masked_card FROM v_sola_payment_methods pm
                     WHERE pm.method_uuid = b.default_method_uuid) AS masked_card
                FROM v_domains d
                LEFT JOIN v_sola_domain_billing b ON b.domain_uuid = d.domain_uuid
                ORDER BY d.domain_name";
        $this->db->execute($sql);
        return $this->db->result() ?: [];
    }

    /**
     * Create or update domain billing configuration.
     */
    public function saveDomainBilling(string $domain_uuid, array $data): void {
        $existing = $this->getDomainBilling($domain_uuid);

        if ($existing) {
            $sets = [];
            $params = [':domain_uuid' => $domain_uuid];
            foreach ($data as $key => $value) {
                $sets[] = "{$key} = :{$key}";
                $params[":{$key}"] = $value;
            }
            $sets[] = "updated_at = NOW()";
            $sql = "UPDATE v_sola_domain_billing SET " . implode(', ', $sets) .
                   " WHERE domain_uuid = :domain_uuid";
            $this->db->execute($sql, $params);
        } else {
            $data['domain_uuid'] = $domain_uuid;
            $columns = implode(', ', array_keys($data));
            $placeholders = implode(', ', array_map(fn($k) => ":{$k}", array_keys($data)));
            $params = [];
            foreach ($data as $key => $value) {
                $params[":{$key}"] = $value;
            }
            $sql = "INSERT INTO v_sola_domain_billing ({$columns}) VALUES ({$placeholders})";
            $this->db->execute($sql, $params);
        }
    }

    /**
     * Update domain billing fields after a charge attempt.
     */
    public function updateDomainChargeResult(string $domain_uuid, string $result, array $extra = []): void {
        $data = array_merge([
            'last_charge_date'   => date('Y-m-d'),
            'last_charge_result' => $result,
        ], $extra);
        $this->saveDomainBilling($domain_uuid, $data);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  PAYMENT METHODS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Save a new payment method (card token).
     */
    public function savePaymentMethod(string $domain_uuid, array $data): string {
        $method_uuid = $this->generateUuid();
        $data['method_uuid'] = $method_uuid;
        $data['domain_uuid'] = $domain_uuid;

        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_map(fn($k) => ":{$k}", array_keys($data)));
        $params = [];
        foreach ($data as $key => $value) {
            $params[":{$key}"] = $value;
        }
        $sql = "INSERT INTO v_sola_payment_methods ({$columns}) VALUES ({$placeholders})";
        $this->db->execute($sql, $params);

        return $method_uuid;
    }

    /**
     * Get all payment methods for a domain.
     */
    public function getPaymentMethods(string $domain_uuid): array {
        $sql = "SELECT * FROM v_sola_payment_methods
                WHERE domain_uuid = :domain_uuid
                ORDER BY is_default DESC, created_at DESC";
        $params = [':domain_uuid' => $domain_uuid];
        $this->db->execute($sql, $params);
        return $this->db->result() ?: [];
    }

    /**
     * Get a specific payment method by UUID.
     */
    public function getPaymentMethod(string $method_uuid): ?array {
        $sql = "SELECT * FROM v_sola_payment_methods WHERE method_uuid = :method_uuid";
        $params = [':method_uuid' => $method_uuid];
        $this->db->execute($sql, $params);
        $rows = $this->db->result();
        return !empty($rows) ? $rows[0] : null;
    }

    /**
     * Get the default payment method for a domain.
     */
    public function getDefaultPaymentMethod(string $domain_uuid): ?array {
        // First try: get the explicitly set default from domain billing config
        $billing = $this->getDomainBilling($domain_uuid);
        if ($billing && !empty($billing['default_method_uuid'])) {
            $method = $this->getPaymentMethod($billing['default_method_uuid']);
            if ($method) {
                return $method;
            }
        }

        // Fallback: get the most recently added card marked as default
        $sql = "SELECT * FROM v_sola_payment_methods
                WHERE domain_uuid = :domain_uuid AND is_default = TRUE
                ORDER BY created_at DESC LIMIT 1";
        $params = [':domain_uuid' => $domain_uuid];
        $this->db->execute($sql, $params);
        $rows = $this->db->result();
        if (!empty($rows)) {
            return $rows[0];
        }

        // Last fallback: just get the most recent card
        $sql = "SELECT * FROM v_sola_payment_methods
                WHERE domain_uuid = :domain_uuid
                ORDER BY created_at DESC LIMIT 1";
        $this->db->execute($sql, $params);
        $rows = $this->db->result();
        return !empty($rows) ? $rows[0] : null;
    }

    /**
     * Set a payment method as default for a domain (unsets others).
     */
    public function setDefaultPaymentMethod(string $domain_uuid, string $method_uuid): void {
        // Unset all defaults for this domain
        $sql = "UPDATE v_sola_payment_methods SET is_default = FALSE WHERE domain_uuid = :domain_uuid";
        $this->db->execute($sql, [':domain_uuid' => $domain_uuid]);

        // Set the new default
        $sql = "UPDATE v_sola_payment_methods SET is_default = TRUE WHERE method_uuid = :method_uuid";
        $this->db->execute($sql, [':method_uuid' => $method_uuid]);

        // Update domain billing config
        $this->saveDomainBilling($domain_uuid, ['default_method_uuid' => $method_uuid]);
    }

    /**
     * Delete a payment method.
     */
    public function deletePaymentMethod(string $method_uuid): void {
        $sql = "DELETE FROM v_sola_payment_methods WHERE method_uuid = :method_uuid";
        $this->db->execute($sql, [':method_uuid' => $method_uuid]);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  TRANSACTIONS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Record a transaction.
     */
    public function createTransaction(array $data): string {
        $transaction_uuid = $this->generateUuid();
        $data['transaction_uuid'] = $transaction_uuid;

        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_map(fn($k) => ":{$k}", array_keys($data)));
        $params = [];
        foreach ($data as $key => $value) {
            $params[":{$key}"] = $value;
        }
        $sql = "INSERT INTO v_sola_transactions ({$columns}) VALUES ({$placeholders})";
        $this->db->execute($sql, $params);

        return $transaction_uuid;
    }

    /**
     * Get a single transaction by UUID.
     */
    public function getTransaction(string $transaction_uuid): ?array {
        $sql = "SELECT t.*, d.domain_name, pm.masked_card, pm.card_type
                FROM v_sola_transactions t
                JOIN v_domains d ON d.domain_uuid = t.domain_uuid
                LEFT JOIN v_sola_payment_methods pm ON pm.method_uuid = t.method_uuid
                WHERE t.transaction_uuid = :transaction_uuid";
        $params = [':transaction_uuid' => $transaction_uuid];
        $this->db->execute($sql, $params);
        $rows = $this->db->result();
        return !empty($rows) ? $rows[0] : null;
    }

    /**
     * Get transactions with optional filters.
     */
    public function getTransactions(array $filters = [], int $limit = 50, int $offset = 0): array {
        $where = [];
        $params = [];

        if (!empty($filters['domain_uuid'])) {
            $where[] = "t.domain_uuid = :domain_uuid";
            $params[':domain_uuid'] = $filters['domain_uuid'];
        }
        if (!empty($filters['charge_type'])) {
            $where[] = "t.charge_type = :charge_type";
            $params[':charge_type'] = $filters['charge_type'];
        }
        if (!empty($filters['result'])) {
            $where[] = "t.result = :result";
            $params[':result'] = $filters['result'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = "t.created_at >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = "t.created_at <= :date_to";
            $params[':date_to'] = $filters['date_to'] . ' 23:59:59';
        }

        $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT t.*, d.domain_name, pm.masked_card, pm.card_type
                FROM v_sola_transactions t
                JOIN v_domains d ON d.domain_uuid = t.domain_uuid
                LEFT JOIN v_sola_payment_methods pm ON pm.method_uuid = t.method_uuid
                {$where_clause}
                ORDER BY t.created_at DESC
                LIMIT :limit OFFSET :offset";
        $params[':limit'] = $limit;
        $params[':offset'] = $offset;

        $this->db->execute($sql, $params);
        return $this->db->result() ?: [];
    }

    /**
     * Get recent transactions (for dashboard).
     */
    public function getRecentTransactions(int $limit = 20): array {
        return $this->getTransactions([], $limit);
    }

    /**
     * Count transactions with filters (for pagination).
     */
    public function countTransactions(array $filters = []): int {
        $where = [];
        $params = [];

        if (!empty($filters['domain_uuid'])) {
            $where[] = "domain_uuid = :domain_uuid";
            $params[':domain_uuid'] = $filters['domain_uuid'];
        }
        if (!empty($filters['charge_type'])) {
            $where[] = "charge_type = :charge_type";
            $params[':charge_type'] = $filters['charge_type'];
        }
        if (!empty($filters['result'])) {
            $where[] = "result = :result";
            $params[':result'] = $filters['result'];
        }

        $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql = "SELECT COUNT(*) AS cnt FROM v_sola_transactions {$where_clause}";
        $this->db->execute($sql, $params);
        $rows = $this->db->result();
        return (int) ($rows[0]['cnt'] ?? 0);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  INTERNATIONAL RATE TABLE
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Get all rate entries.
     */
    public function getRateTable(string $search = '', int $limit = 100, int $offset = 0): array {
        $where = '';
        $params = [':limit' => $limit, ':offset' => $offset];
        if (!empty($search)) {
            $where = "WHERE country_name ILIKE :search OR dial_prefix LIKE :prefix_search";
            $params[':search'] = '%' . $search . '%';
            $params[':prefix_search'] = $search . '%';
        }
        $sql = "SELECT * FROM v_sola_intl_rate_table {$where}
                ORDER BY country_name, dial_prefix
                LIMIT :limit OFFSET :offset";
        $this->db->execute($sql, $params);
        return $this->db->result() ?: [];
    }

    /**
     * Get a single rate entry.
     */
    public function getRate(string $rate_uuid): ?array {
        $sql = "SELECT * FROM v_sola_intl_rate_table WHERE rate_uuid = :rate_uuid";
        $this->db->execute($sql, [':rate_uuid' => $rate_uuid]);
        $rows = $this->db->result();
        return !empty($rows) ? $rows[0] : null;
    }

    /**
     * Save or update a rate entry.
     */
    public function saveRate(array $data, string $rate_uuid = null): string {
        if ($rate_uuid) {
            $sets = [];
            $params = [':rate_uuid' => $rate_uuid];
            foreach ($data as $key => $value) {
                $sets[] = "{$key} = :{$key}";
                $params[":{$key}"] = $value;
            }
            $sets[] = "updated_at = NOW()";
            $sql = "UPDATE v_sola_intl_rate_table SET " . implode(', ', $sets) .
                   " WHERE rate_uuid = :rate_uuid";
            $this->db->execute($sql, $params);
            return $rate_uuid;
        } else {
            $rate_uuid = $this->generateUuid();
            $data['rate_uuid'] = $rate_uuid;
            $columns = implode(', ', array_keys($data));
            $placeholders = implode(', ', array_map(fn($k) => ":{$k}", array_keys($data)));
            $params = [];
            foreach ($data as $key => $value) {
                $params[":{$key}"] = $value;
            }
            $sql = "INSERT INTO v_sola_intl_rate_table ({$columns}) VALUES ({$placeholders})";
            $this->db->execute($sql, $params);
            return $rate_uuid;
        }
    }

    /**
     * Find a rate by dial prefix (exact match).
     */
    public function getRateByPrefix(string $prefix): ?array {
        $sql = "SELECT * FROM v_sola_intl_rate_table
                WHERE dial_prefix = :prefix AND enabled = TRUE";
        $this->db->execute($sql, [':prefix' => $prefix]);
        $rows = $this->db->result();
        return !empty($rows) ? $rows[0] : null;
    }

    /**
     * Count rate table entries.
     */
    public function countRates(string $search = ''): int {
        $where = '';
        $params = [];
        if (!empty($search)) {
            $where = "WHERE country_name ILIKE :search OR dial_prefix LIKE :prefix_search";
            $params[':search'] = '%' . $search . '%';
            $params[':prefix_search'] = $search . '%';
        }
        $sql = "SELECT COUNT(*) AS cnt FROM v_sola_intl_rate_table {$where}";
        $this->db->execute($sql, $params);
        $rows = $this->db->result();
        return (int) ($rows[0]['cnt'] ?? 0);
    }

    /**
     * Delete a rate entry.
     */
    public function deleteRate(string $rate_uuid): void {
        $sql = "DELETE FROM v_sola_intl_rate_table WHERE rate_uuid = :rate_uuid";
        $this->db->execute($sql, [':rate_uuid' => $rate_uuid]);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  CALL CHARGES
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Create a call charge record.
     */
    public function createCallCharge(array $data): string {
        $uuid = $this->generateUuid();
        $data['call_charge_uuid'] = $uuid;

        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_map(fn($k) => ":{$k}", array_keys($data)));
        $params = [];
        foreach ($data as $key => $value) {
            $params[":{$key}"] = $value;
        }
        $sql = "INSERT INTO v_sola_call_charges ({$columns}) VALUES ({$placeholders})";
        $this->db->execute($sql, $params);
        return $uuid;
    }

    /**
     * Check if a CDR has already been processed.
     */
    public function isCallChargeExists(string $xml_cdr_uuid): bool {
        $sql = "SELECT 1 FROM v_sola_call_charges WHERE xml_cdr_uuid = :xml_cdr_uuid LIMIT 1";
        $this->db->execute($sql, [':xml_cdr_uuid' => $xml_cdr_uuid]);
        $rows = $this->db->result();
        return !empty($rows);
    }

    /**
     * Get pending call charges for a domain.
     */
    public function getPendingCallCharges(string $domain_uuid): array {
        $sql = "SELECT * FROM v_sola_call_charges
                WHERE domain_uuid = :domain_uuid AND status = 'pending'
                ORDER BY call_date";
        $this->db->execute($sql, [':domain_uuid' => $domain_uuid]);
        return $this->db->result() ?: [];
    }

    /**
     * Get pending call charges total for a domain.
     */
    public function getPendingCallChargesTotal(string $domain_uuid): float {
        $sql = "SELECT COALESCE(SUM(charge_amount_rounded), 0) AS total
                FROM v_sola_call_charges
                WHERE domain_uuid = :domain_uuid AND status = 'pending'";
        $this->db->execute($sql, [':domain_uuid' => $domain_uuid]);
        $rows = $this->db->result();
        return (float) ($rows[0]['total'] ?? 0);
    }

    /**
     * Mark pending call charges as charged, linking them to a transaction.
     */
    public function markCallChargesCharged(string $domain_uuid, string $transaction_uuid): void {
        $sql = "UPDATE v_sola_call_charges
                SET status = 'charged', transaction_uuid = :transaction_uuid
                WHERE domain_uuid = :domain_uuid AND status = 'pending'";
        $this->db->execute($sql, [
            ':transaction_uuid' => $transaction_uuid,
            ':domain_uuid'      => $domain_uuid,
        ]);
    }

    /**
     * Get call charges with filters (for admin view).
     */
    public function getCallCharges(array $filters = [], int $limit = 50, int $offset = 0): array {
        $where = [];
        $params = [];

        if (!empty($filters['domain_uuid'])) {
            $where[] = "cc.domain_uuid = :domain_uuid";
            $params[':domain_uuid'] = $filters['domain_uuid'];
        }
        if (!empty($filters['status'])) {
            $where[] = "cc.status = :status";
            $params[':status'] = $filters['status'];
        }

        $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT cc.*, d.domain_name
                FROM v_sola_call_charges cc
                JOIN v_domains d ON d.domain_uuid = cc.domain_uuid
                {$where_clause}
                ORDER BY cc.call_date DESC
                LIMIT :limit OFFSET :offset";
        $params[':limit'] = $limit;
        $params[':offset'] = $offset;

        $this->db->execute($sql, $params);
        return $this->db->result() ?: [];
    }

    // ═══════════════════════════════════════════════════════════════════
    //  EXTENSIONS (read from FusionPBX core tables)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Count active extensions for a domain.
     */
    public function countActiveExtensions(string $domain_uuid): int {
        $sql = "SELECT COUNT(*) AS cnt FROM v_extensions
                WHERE domain_uuid = :domain_uuid AND enabled = 'true'";
        $this->db->execute($sql, [':domain_uuid' => $domain_uuid]);
        $rows = $this->db->result();
        return (int) ($rows[0]['cnt'] ?? 0);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  CDR (read from FusionPBX core tables)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Get outbound CDRs since a given timestamp that haven't been billed.
     */
    public function getUnbilledOutboundCdrs(string $since): array {
        $sql = "SELECT c.xml_cdr_uuid, c.domain_uuid, c.destination_number,
                       c.billsec, c.start_stamp, c.direction
                FROM v_xml_cdr c
                WHERE c.direction = 'outbound'
                  AND c.billsec > 0
                  AND c.start_stamp >= :since
                  AND NOT EXISTS (
                      SELECT 1 FROM v_sola_call_charges cc
                      WHERE cc.xml_cdr_uuid = c.xml_cdr_uuid
                  )
                ORDER BY c.start_stamp";
        $this->db->execute($sql, [':since' => $since]);
        return $this->db->result() ?: [];
    }

    // ═══════════════════════════════════════════════════════════════════
    //  AUDIT LOG
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Write an audit log entry.
     */
    public function writeAuditLog(string $domain_uuid = null, string $actor, string $action, array $detail = [], string $ip = null): void {
        $sql = "INSERT INTO v_sola_audit_log (domain_uuid, actor, action, detail, ip_address)
                VALUES (:domain_uuid, :actor, :action, :detail, :ip_address)";
        $this->db->execute($sql, [
            ':domain_uuid' => $domain_uuid,
            ':actor'       => $actor,
            ':action'      => $action,
            ':detail'      => json_encode($detail),
            ':ip_address'  => $ip,
        ]);
    }

    /**
     * Get audit log entries.
     */
    public function getAuditLog(string $domain_uuid = null, int $limit = 100): array {
        $where = '';
        $params = [':limit' => $limit];
        if ($domain_uuid) {
            $where = 'WHERE domain_uuid = :domain_uuid';
            $params[':domain_uuid'] = $domain_uuid;
        }
        $sql = "SELECT * FROM v_sola_audit_log {$where} ORDER BY created_at DESC LIMIT :limit";
        $this->db->execute($sql, $params);
        return $this->db->result() ?: [];
    }

    // ═══════════════════════════════════════════════════════════════════
    //  DASHBOARD STATS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Get dashboard statistics.
     */
    public function getDashboardStats(): array {
        $stats = [];

        // Revenue this month
        $sql = "SELECT COALESCE(SUM(amount), 0) AS total
                FROM v_sola_transactions
                WHERE result = 'A'
                  AND charge_type IN ('monthly_invoice', 'manual')
                  AND created_at >= date_trunc('month', CURRENT_DATE)";
        $this->db->execute($sql);
        $rows = $this->db->result();
        $stats['revenue_this_month'] = (float) ($rows[0]['total'] ?? 0);

        // Revenue YTD
        $sql = "SELECT COALESCE(SUM(amount), 0) AS total
                FROM v_sola_transactions
                WHERE result = 'A'
                  AND charge_type IN ('monthly_invoice', 'manual')
                  AND created_at >= date_trunc('year', CURRENT_DATE)";
        $this->db->execute($sql);
        $rows = $this->db->result();
        $stats['revenue_ytd'] = (float) ($rows[0]['total'] ?? 0);

        // Active billing domains
        $sql = "SELECT COUNT(*) AS cnt FROM v_sola_domain_billing WHERE billing_enabled = TRUE";
        $this->db->execute($sql);
        $rows = $this->db->result();
        $stats['active_domains'] = (int) ($rows[0]['cnt'] ?? 0);

        // Failed domains
        $sql = "SELECT COUNT(*) AS cnt FROM v_sola_domain_billing
                WHERE billing_enabled = TRUE AND calls_restricted = TRUE";
        $this->db->execute($sql);
        $rows = $this->db->result();
        $stats['failed_domains'] = (int) ($rows[0]['cnt'] ?? 0);

        // Pending international charges total
        $sql = "SELECT COALESCE(SUM(charge_amount_rounded), 0) AS total
                FROM v_sola_call_charges WHERE status = 'pending'";
        $this->db->execute($sql);
        $rows = $this->db->result();
        $stats['pending_intl_charges'] = (float) ($rows[0]['total'] ?? 0);

        return $stats;
    }

    /**
     * Get failed domains for the dashboard.
     */
    public function getFailedDomains(): array {
        $sql = "SELECT b.*, d.domain_name,
                    t.amount AS last_amount, t.result_message AS last_error
                FROM v_sola_domain_billing b
                JOIN v_domains d ON d.domain_uuid = b.domain_uuid
                LEFT JOIN v_sola_transactions t ON (
                    t.domain_uuid = b.domain_uuid
                    AND t.result != 'A'
                    AND t.created_at = (
                        SELECT MAX(t2.created_at) FROM v_sola_transactions t2
                        WHERE t2.domain_uuid = b.domain_uuid AND t2.result != 'A'
                    )
                )
                WHERE b.billing_enabled = TRUE
                  AND b.last_charge_result IN ('D', 'E')
                ORDER BY b.last_charge_date DESC";
        $this->db->execute($sql);
        return $this->db->result() ?: [];
    }

    // ═══════════════════════════════════════════════════════════════════
    //  REPORTS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Revenue by domain for a date range.
     */
    public function getRevenueByDomain(string $date_from, string $date_to): array {
        $sql = "SELECT d.domain_name, t.domain_uuid,
                    SUM(CASE WHEN t.result = 'A' THEN t.amount ELSE 0 END) AS total_revenue,
                    COUNT(CASE WHEN t.result = 'A' THEN 1 END) AS successful_charges,
                    COUNT(CASE WHEN t.result != 'A' THEN 1 END) AS failed_charges
                FROM v_sola_transactions t
                JOIN v_domains d ON d.domain_uuid = t.domain_uuid
                WHERE t.created_at >= :date_from
                  AND t.created_at <= :date_to
                GROUP BY d.domain_name, t.domain_uuid
                ORDER BY total_revenue DESC";
        $this->db->execute($sql, [
            ':date_from' => $date_from,
            ':date_to'   => $date_to . ' 23:59:59',
        ]);
        return $this->db->result() ?: [];
    }

    /**
     * Monthly revenue summary.
     */
    public function getMonthlyRevenueSummary(int $months = 12): array {
        $sql = "SELECT
                    to_char(created_at, 'YYYY-MM') AS month,
                    SUM(CASE WHEN result = 'A' THEN amount ELSE 0 END) AS revenue,
                    COUNT(CASE WHEN result = 'A' THEN 1 END) AS successful,
                    COUNT(CASE WHEN result != 'A' THEN 1 END) AS failed
                FROM v_sola_transactions
                WHERE created_at >= (CURRENT_DATE - interval ':months months')
                GROUP BY to_char(created_at, 'YYYY-MM')
                ORDER BY month DESC";
        // Note: interval parameterization doesn't work, use string concat
        $sql = "SELECT
                    to_char(created_at, 'YYYY-MM') AS month,
                    SUM(CASE WHEN result = 'A' THEN amount ELSE 0 END) AS revenue,
                    COUNT(CASE WHEN result = 'A' THEN 1 END) AS successful,
                    COUNT(CASE WHEN result != 'A' THEN 1 END) AS failed
                FROM v_sola_transactions
                WHERE created_at >= (CURRENT_DATE - interval '{$months} months')
                GROUP BY to_char(created_at, 'YYYY-MM')
                ORDER BY month DESC";
        $this->db->execute($sql);
        return $this->db->result() ?: [];
    }

    // ═══════════════════════════════════════════════════════════════════
    //  SETTINGS (stored in v_default_settings)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Get a Sola Billing setting.
     */
    public function getSetting(string $subcategory): ?string {
        $sql = "SELECT default_setting_value FROM v_default_settings
                WHERE default_setting_category = 'sola_billing'
                  AND default_setting_subcategory = :subcategory
                  AND default_setting_enabled = 'true'
                LIMIT 1";
        $this->db->execute($sql, [':subcategory' => $subcategory]);
        $rows = $this->db->result();
        return !empty($rows) ? $rows[0]['default_setting_value'] : null;
    }

    /**
     * Set a Sola Billing setting.
     */
    public function saveSetting(string $subcategory, string $value, string $type = 'text'): void {
        // Check if exists
        $sql = "SELECT default_setting_uuid FROM v_default_settings
                WHERE default_setting_category = 'sola_billing'
                  AND default_setting_subcategory = :subcategory";
        $this->db->execute($sql, [':subcategory' => $subcategory]);
        $rows = $this->db->result();

        if (!empty($rows)) {
            $sql = "UPDATE v_default_settings
                    SET default_setting_value = :value,
                        default_setting_enabled = 'true'
                    WHERE default_setting_category = 'sola_billing'
                      AND default_setting_subcategory = :subcategory";
            $this->db->execute($sql, [':value' => $value, ':subcategory' => $subcategory]);
        } else {
            $uuid = $this->generateUuid();
            $sql = "INSERT INTO v_default_settings
                    (default_setting_uuid, default_setting_category, default_setting_subcategory,
                     default_setting_name, default_setting_value, default_setting_enabled)
                    VALUES (:uuid, 'sola_billing', :subcategory, :type, :value, 'true')";
            $this->db->execute($sql, [
                ':uuid'        => $uuid,
                ':subcategory' => $subcategory,
                ':type'        => $type,
                ':value'       => $value,
            ]);
        }
    }

    /**
     * Get all Sola Billing settings.
     */
    public function getAllSettings(): array {
        $sql = "SELECT default_setting_subcategory, default_setting_value
                FROM v_default_settings
                WHERE default_setting_category = 'sola_billing'
                  AND default_setting_enabled = 'true'
                ORDER BY default_setting_subcategory";
        $this->db->execute($sql);
        $rows = $this->db->result() ?: [];

        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['default_setting_subcategory']] = $row['default_setting_value'];
        }
        return $settings;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  UTILITY
    // ═══════════════════════════════════════════════════════════════════

    private function generateUuid(): string {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
