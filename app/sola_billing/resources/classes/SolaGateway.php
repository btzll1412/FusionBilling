<?php
/**
 * SolaGateway — Wrapper for Sola Payments Transaction API v5
 *                and Customer & Recurring API v2
 *
 * Sola is the rebranded Cardknox gateway. Both endpoint bases still use
 * the Cardknox URLs as of Sola documentation.
 */
class SolaGateway {

    private string $xKey;
    private bool   $sandbox;
    private string $apiVersion      = '5.0.0';
    private string $transactionUrl  = 'https://x1.cardknox.com/gatewayjson';
    private string $recurringUrl    = 'https://api.cardknox.com/v2/recurring';
    private string $softwareName    = 'FusionPBX-SolaBilling';
    private string $softwareVersion = '1.0.0';

    public function __construct(string $xKey, bool $sandbox = false) {
        $this->xKey    = $xKey;
        $this->sandbox = $sandbox;
    }

    // ─── CHARGE A SAVED TOKEN (card on file) ────────────────────────

    public function chargeToken(
        string $xToken,
        float  $amount,
        string $invoice,
        string $description = '',
        string $ip = ''
    ): array {
        return $this->transact([
            'xCommand'     => 'cc:Sale',
            'xAmount'      => $this->formatAmount($amount),
            'xToken'       => $xToken,
            'xInvoice'     => $invoice,
            'xDescription' => $description,
            'xIP'          => $ip,
        ]);
    }

    // ─── SAVE A CARD (returns xToken for storage) ───────────────────
    // Call this when admin adds a card manually via iFields in the UI.
    // The single-use token (SUT) comes from the browser via iFields JS.

    public function saveCard(
        string $sut,    // single-use token from iFields
        string $exp,    // MMYY
        string $name
    ): array {
        return $this->transact([
            'xCommand' => 'cc:Save',
            'xToken'   => $sut,
            'xExp'     => $exp,
            'xName'    => $name,
        ]);
    }

    // ─── AUTHORIZE (for test charges, e.g. $0.01 verification) ──────

    public function authorize(
        string $xToken,
        float  $amount,
        string $invoice = '',
        string $ip = ''
    ): array {
        return $this->transact([
            'xCommand' => 'cc:AuthOnly',
            'xAmount'  => $this->formatAmount($amount),
            'xToken'   => $xToken,
            'xInvoice' => $invoice,
            'xIP'      => $ip,
        ]);
    }

    // ─── VOID (same-day, before batch settles) ──────────────────────

    public function void(string $xRefNum): array {
        return $this->transact([
            'xCommand' => 'cc:Void',
            'xRefNum'  => $xRefNum,
        ]);
    }

    // ─── REFUND (settled transaction) ───────────────────────────────

    public function refund(string $xRefNum, float $amount): array {
        return $this->transact([
            'xCommand' => 'cc:Refund',
            'xRefNum'  => $xRefNum,
            'xAmount'  => $this->formatAmount($amount),
        ]);
    }

    // ─── CUSTOMER PROFILE (Recurring API) ───────────────────────────

    public function createCustomer(string $name, string $email, string $internalId): array {
        return $this->recurringPost('/customers', [
            'Name'            => $name,
            'Email'           => $email,
            'CustomerID'      => $internalId,
            'SoftwareName'    => $this->softwareName,
            'SoftwareVersion' => $this->softwareVersion,
        ]);
    }

    // ─── STATUS HELPERS ─────────────────────────────────────────────

    public function isApproved(array $response): bool {
        return isset($response['xResult']) && strtoupper($response['xResult']) === 'A';
    }

    public function isDeclined(array $response): bool {
        return isset($response['xResult']) && strtoupper($response['xResult']) === 'D';
    }

    public function isError(array $response): bool {
        return isset($response['xResult']) && strtoupper($response['xResult']) === 'E';
    }

    public function getRefNum(array $response): ?int {
        return isset($response['xRefNum']) ? (int) $response['xRefNum'] : null;
    }

    public function getAuthCode(array $response): ?string {
        return $response['xAuthCode'] ?? null;
    }

    public function getMaskedCard(array $response): ?string {
        return $response['xMaskedCardNumber'] ?? null;
    }

    public function getCardType(array $response): ?string {
        return $response['xCardType'] ?? null;
    }

    public function getToken(array $response): ?string {
        return $response['xToken'] ?? null;
    }

    public function getErrorMessage(array $response): string {
        return $response['xError'] ?? $response['xStatus'] ?? 'Unknown error';
    }

    // ─── INTERNAL HELPERS ───────────────────────────────────────────

    private function formatAmount(float $amount): string {
        return number_format($amount, 2, '.', '');
    }

    private function transact(array $params): array {
        $params['xKey']             = $this->xKey;
        $params['xVersion']         = $this->apiVersion;
        $params['xSoftwareName']    = $this->softwareName;
        $params['xSoftwareVersion'] = $this->softwareVersion;

        $ch = curl_init($this->transactionUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false || !empty($err)) {
            return ['xResult' => 'E', 'xError' => 'cURL error: ' . $err];
        }
        if ($code !== 200) {
            return ['xResult' => 'E', 'xError' => 'HTTP ' . $code];
        }
        return json_decode($raw, true) ?? ['xResult' => 'E', 'xError' => 'Invalid JSON response'];
    }

    private function recurringPost(string $endpoint, array $payload): array {
        $ch = curl_init($this->recurringUrl . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: ' . $this->xKey,
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        return json_decode($raw, true) ?? ['Result' => 'E', 'Error' => 'Invalid response'];
    }
}
