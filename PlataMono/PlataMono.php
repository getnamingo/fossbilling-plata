<?php
/**
 * Monobank Plata payment gateway for FOSSBilling
 *
 */

use FOSSBilling\InjectionAwareInterface;

class Payment_Adapter_PlataMono implements InjectionAwareInterface
{
    protected ?Pimple\Container $di = null;

    private string $apiBase   = 'https://api.monobank.ua';
    private string $createUrl = '/api/merchant/invoice/create';
    private string $pubKeyUrl = '/api/merchant/pubkey';

    /** Plata status → FB transaction status mapping */
    private array $statusMap = [
        'success'    => 'succeeded',
        'processing' => 'pending',
        'hold'       => 'pending',
        'expired'    => 'failed',
        'failure'    => 'failed',
        'error'      => 'failed',
        'created'    => 'pending',
        'reversed'   => 'refunded',
        'reversal'   => 'refunded',
        // default → 'pending'
    ];

    public function setDi(Pimple\Container $di): void { $this->di = $di; }
    public function getDi(): ?Pimple\Container { return $this->di; }

    public function __construct(private array $config)
    {
        // Basic config validation
        if (!isset($this->config['test_mode'])) {
            $this->config['test_mode'] = false;
        }
        if ($this->config['test_mode']) {
            if (empty($this->config['test_token'])) {
                throw new Payment_Exception('Plata (Monobank) not configured: Sandbox token missing', null, 4001);
            }
        } else {
            if (empty($this->config['live_token'])) {
                throw new Payment_Exception('Plata (Monobank) not configured: Live token missing', null, 4001);
            }
        }
        if (empty($this->config['language'])) {
            $this->config['language'] = 'uk';
        }
        if (!in_array($this->config['language'], ['uk','en'])) {
            throw new Payment_Exception('Plata: language must be "uk" or "en"', null, 4001);
        }
        if (empty($this->config['payment_type'])) {
            $this->config['payment_type'] = 'debit'; // or 'hold'
        }
    }

    public static function getConfig()
    {
        return [
            'supports_one_time_payments' => true,
            'supports_subscriptions'     => false,
            'description' => 'Monobank Plata acquiring: creates an invoice and redirects the customer to pay on Monobank’s hosted page.',
            'logo' => [
                'logo'   => 'PlataMono/PlataMono.png',
                'height' => '30px',
                'width'  => '130px',
            ],
            'form' => [
                'live_token' => [
                    'password', [
                        'label' => 'Live X-Token',
                    ]
                ],
                'test_token' => [
                    'password', [
                        'label' => 'Sandbox X-Token (optional)',
                        'required' => false,
                    ]
                ],
                'language' => [
                    'text', [
                        'label' => 'Language ("uk" or "en")',
                        'default' => 'uk',
                    ],
                ],
            ],
        ];
    }

    private function token(): string
    {
        return $this->config['test_mode'] ? $this->config['test_token'] : $this->config['live_token'];
    }

    public function getHtml($api_admin, $invoice_id, $subscription)
    {
        /** @var Model_Invoice $invoice */
        $invoice = $this->di['db']->load('Invoice', $invoice_id);
        $invoiceService = $this->di['mod_service']('Invoice');

        // --- Build Plata Create Invoice payload ---
        $amountMajor = $invoiceService->getTotalWithTax($invoice); // e.g. 42.00
        $amountMinor = (int) round($amountMajor * 100);            // 4200 kopiyky

        if (strtoupper($invoice->currency) !== 'UAH') {
            throw new Payment_Exception('Plata: only UAH (980) supported by this adapter right now.');
        }

        $payGatewayService = $this->di['mod_service']('Invoice', 'PayGateway');
        $gateway           = $this->di['db']->findOne('PayGateway', 'gateway = "PlataMono"');

        $redirectUrl = $this->di['tools']->url('invoice/' . $invoice->hash);
        $webHookUrl  = $payGatewayService->getCallbackUrl($gateway, $invoice);
        //$webHookUrl = $this->di['tools']->url('ipn.php', ['gateway_id' => $gateway->id]);

        $title = $this->getInvoiceTitle($invoice);

        $payload = [
            'amount' => $amountMinor,
            'ccy'    => 980,
            'merchantPaymInfo' => [
                'reference'   => $invoice->hash,
                'destination' => $title,
                'comment'     => $title,
                // Minimal basket (Monobank accepts more details; keep simple by default)
                'basketOrder' => [[
                    'name'  => $title,
                    'qty'   => 1,
                    'sum'   => $amountMinor,
                    'total' => $amountMinor,
                    'unit'  => 'шт.',
                    'code'  => (string)$invoice->id,
                ]],
            ],
            'redirectUrl' => $redirectUrl,
            'webHookUrl'  => $webHookUrl,
            'validity'    => 24 * 3600, // 24h
            'paymentType' => 'debit',
            // Optional: qrId, code (sub-merchant), saveCardData, etc.
        ];

        $resp = $this->httpJson('POST', $this->apiBase . $this->createUrl, $payload, [
            'X-Token: ' . $this->token(),
            'X-Cms: FOSSBilling',
            'X-Cms-Version: ' . FOSSBilling\Version::VERSION,
        ]);

        if (!isset($resp['invoiceId'], $resp['pageUrl'])) {
            throw new Payment_Exception('Plata: failed to create invoice (no invoiceId/pageUrl returned).');
        }

        // Store minimal link on transaction draft? Usually not needed; we just redirect.
        $action = htmlspecialchars($resp['pageUrl'], ENT_QUOTES, 'UTF-8');

        // Simple auto-redirect (progressive)
        return sprintf(
            '<p>%s</p><p><a href="%s">%s</a><script>location.href=%s;</script>',
            __('Redirecting to Monobank…'),
            $action,
            __('Click here if you are not redirected.'),
            json_encode($action)
        );
    }

    public function getInvoiceTitle(Model_Invoice $invoice): string
    {
        $items = $this->di['db']->getAll('SELECT title FROM invoice_item WHERE invoice_id = :id', [':id' => $invoice->id]);
        $first = $items[0]['title'] ?? '';
        $params = [
            ':id'    => sprintf('%05s', $invoice->nr),
            ':serie' => $invoice->serie,
            ':title' => $first,
        ];
        $t = __trans('Payment for invoice :serie:id [:title]', $params);
        if (count($items) > 1) {
            $t = __trans('Payment for invoice :serie:id', $params);
        }
        return $t;
    }

    /**
     * Webhook handler
     * This will be invoked by FOSSBilling via the gateway callback URL.
     */
    public function processTransaction($api_admin, $id, $data, $gateway_id)
    {
        $tx = $this->di['db']->getExistingModelById('Transaction', $id);

        // Raw JSON from webhook
        $raw = file_get_contents('php://input');
        if (!$raw) {
            throw new FOSSBilling\Exception('Plata webhook: empty body');
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            throw new FOSSBilling\Exception('Plata webhook: invalid JSON');
        }

        // Verify signature using Monobank public key
        $xSign = $this->header('X-Sign');
        if (!$xSign) {
            throw new FOSSBilling\Exception('Plata webhook: missing X-Sign header');
        }
        $signature = base64_decode($xSign);

        // Fetch/cached public key (base64 PEM)
        $pubKeyB64 = $this->getMonobankPublicKeyB64();
        $pubKeyPem = base64_decode($pubKeyB64);
        $pubKey    = openssl_pkey_get_public($pubKeyPem);
        if (!$pubKey) {
            throw new FOSSBilling\Exception('Plata webhook: invalid public key');
        }

        $ok = openssl_verify($raw, $signature, $pubKey, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            throw new FOSSBilling\Exception('Plata webhook: signature verification failed');
        }

        // Extract essentials
        $plataStatus = $json['status'] ?? 'processing';
        $invoiceHash = $json['reference'] ?? null;
        $amountMinor = $json['finalAmount'] ?? $json['amount'] ?? null;
        $currency    = ($json['ccy'] ?? 980) === 980 ? 'UAH' : 'UAH'; // adapter is UAH-only now
        $plataTxnId  = $json['invoiceId'] ?? null;

        if (!$invoiceHash || !$amountMinor || !$plataTxnId) {
            throw new FOSSBilling\Exception('Plata webhook: missing required fields');
        }

        // Map to invoice
        /** @var Model_Invoice $invoice */
        $invoice = $this->di['db']->findOne('Invoice', 'hash = :h', [':h' => $invoiceHash]);
        if (!$invoice instanceof Model_Invoice) {
            throw new FOSSBilling\Exception('Plata webhook: invoice not found');
        }

        // Convert kopiyky → UAH
        $amountMajor = round(((int)$amountMinor) / 100, 2);

        // Update tx
        $tx->invoice_id = $invoice->id;
        $tx->txn_id     = $plataTxnId;
        $tx->amount     = $amountMajor;
        $tx->currency   = $currency;
        $tx->txn_status = $plataStatus;
        $tx->ip         = $_SERVER['REMOTE_ADDR'] ?? null;
        $tx->type = 'Payment';

        $statusMapped = $this->statusMap[$plataStatus] ?? 'pending';
        $tx->status   = $statusMapped;
        $tx->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($tx);

        // On success → credit funds & pay invoice
        if ($statusMapped === 'succeeded') {
            $clientService  = $this->di['mod_service']('client');
            $invoiceService = $this->di['mod_service']('Invoice');

            $client = $this->di['db']->getExistingModelById('Client', $invoice->client_id);

            $desc = 'Plata transaction ' . $plataTxnId;
            $bd = [
                'amount' => $tx->amount,
                'description' => $desc,
                'type' => 'transaction',
                'rel_id' => $tx->id,
            ];

            $clientService->addFunds($client, $bd['amount'], $bd['description'], $bd);

            if ($tx->invoice_id) {
                $invoiceService->payInvoiceWithCredits($invoice);
            } else {
                $invoiceService->doBatchPayWithCredits(['client_id' => $client->id]);
            }
        }

        // Return 200 OK
        echo 'OK';
    }

    /** -------- Helpers -------- */

    private function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$key] ?? null;
    }

    private function getMonobankPublicKeyB64(): string
    {
        // Consider caching in DB or filesystem for ~24h.
        $resp = $this->httpJson('GET', $this->apiBase . $this->pubKeyUrl, null, [
            'X-Token: ' . $this->token(),
        ]);
        // API returns base64 of PEM (as in your sample)
        if (empty($resp['key'])) {
            throw new Payment_Exception('Plata: could not fetch public key');
        }
        return $resp['key'];
    }

    private function httpJson(string $method, string $url, ?array $body = null, array $headers = [], int $timeout = 10): array
    {
        $ch = curl_init();
        $baseHeaders = [
            'Accept: application/json',
        ];
        if ($body !== null) {
            $baseHeaders[] = 'Content-Type: application/json';
        }
        $headers = array_merge($baseHeaders, $headers);

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        }

        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new Payment_Exception('Plata HTTP error: ' . $err);
        }
        $data = json_decode($raw, true);
        if ($code >= 400 || !is_array($data)) {
            throw new Payment_Exception('Plata API error ['.$code.']: ' . $raw);
        }
        return $data;
    }
}