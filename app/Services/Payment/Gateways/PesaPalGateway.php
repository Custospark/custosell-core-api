<?php

namespace App\Services\Payment\Gateways;

use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\Gateways\Exceptions\GatewayException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PesaPalGateway implements PaymentGatewayInterface
{
    private string $baseUrl;
    private string $consumerKey;
    private string $consumerSecret;
    private string $ipnId;

    public function __construct()
    {
        $cfg = config('pesapal');

        $env = $cfg['environment'] ?? 'sandbox';
        $this->baseUrl = $env === 'production'
            ? $cfg['base_url_production']
            : $cfg['base_url_sandbox'];

        $this->consumerKey = (string) ($cfg['consumer_key'] ?? '');
        $this->consumerSecret = (string) ($cfg['consumer_secret'] ?? '');
        $this->ipnId = (string) ($cfg['ipn_id'] ?? '');
    }

    private function isBypassMode(): bool
    {
        if (app()->environment('production')) {
            return false;
        }
        return config('pesapal.bypass', false) === true;
    }

    private function forgetTokenCache(): void
    {
        Cache::forget('pesapal_token_' . config('pesapal.environment'));
    }

    public function initiate(array $payload): array
    {
        if ($this->isBypassMode()) {
            $merchantRef = 'CUSTO-' . $payload['payment_id'] . '-' . now()->format('YmdHis');
            Log::info('[PesaPal] Bypass mode — payment auto-approved', [
                'payment_id' => $payload['payment_id'],
                'reference' => $merchantRef,
            ]);
            return [
                'success' => true,
                'gateway_ref' => $merchantRef,
                'gateway_txn_id' => 'BYPASS-' . $payload['payment_id'],
                'redirect_url' => null,
                'type' => 'bypass',
                'message' => 'Payment bypassed (development mode).',
                'raw_response' => ['bypass' => true],
            ];
        }

        return $this->initiateWithRetry($payload);
    }

    private function initiateWithRetry(array $payload, bool $isRetry = false): array
    {
        $merchantRef = 'CUSTO-' . $payload['payment_id'] . '-' . now()->format('YmdHis');
        $accessToken = $this->getAccessToken();

        try {
            $ipnId = $this->ipnId ?: $this->registerIpn($accessToken);
        } catch (GatewayException $e) {
            if ($this->isHttpStatus($e, 401) && !$isRetry) {
                Log::warning('[PesaPal] IPN registration got 401 — retrying with fresh token');
                $this->forgetTokenCache();
                return $this->initiateWithRetry($payload, true);
            }
            throw $e;
        }

        $body = [
            'id' => $merchantRef,
            'currency' => strtoupper($payload['currency']),
            'amount' => (float) $payload['amount'],
            'description' => $payload['description'] ?? 'Custosell subscription payment',
            'callback_url' => config('pesapal.callback_url'),
            'redirect_mode' => 'TOP_WINDOW',
            'notification_id' => $ipnId,
            'billing_address' => [
                'email_address' => $payload['email'] ?? 'noreply@custosell.com',
                'phone_number' => $payload['phone_number'] ?? '',
                'country_code' => 'UG',
                'first_name' => $payload['customer_name'] ?? 'Custosell Customer',
                'last_name' => '',
            ],
        ];

        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->timeout(30)
            ->post("{$this->baseUrl}/api/Transactions/SubmitOrderRequest", $body);

        $data = $response->json() ?? [];

        if (!$response->successful() || empty($data['redirect_url'])) {
            if ($response->status() === 401 && !$isRetry) {
                Log::warning('[PesaPal] Order submission got 401 — retrying with fresh token');
                $this->forgetTokenCache();
                return $this->initiateWithRetry($payload, true);
            }

            Log::error('[PesaPal] Order submission failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'data' => $data,
            ]);
            $errorMsg = null;
            foreach (['error', 'message', 'error_message'] as $key) {
                $val = $data[$key] ?? null;
                if (is_string($val) && $val !== '') { $errorMsg = $val; break; }
                if (is_array($val)) {
                    foreach (['message', 'error', 'description'] as $sub) {
                        if (isset($val[$sub]) && is_string($val[$sub]) && $val[$sub] !== '') {
                            $errorMsg = $val[$sub]; break 2;
                        }
                    }
                    $errorMsg = json_encode($val); break;
                }
            }
            $detail = $errorMsg ?? "HTTP {$response->status()}";
            throw new GatewayException(
                "PesaPal order submission failed: {$detail}",
                'pesapal',
                $data
            );
        }

        Log::info('[PesaPal] Order submitted', [
            'order_tracking_id' => $data['order_tracking_id'],
            'merchant_reference' => $merchantRef,
        ]);

        return [
            'success' => true,
            'gateway_ref' => $merchantRef,
            'gateway_txn_id' => $data['order_tracking_id'],
            'redirect_url' => $data['redirect_url'],
            'type' => 'redirect',
            'message' => 'Redirecting to PesaPal payment page.',
            'raw_response' => $data,
        ];
    }

    private function isHttpStatus(GatewayException $e, int $status): bool
    {
        return str_contains($e->getMessage(), "HTTP {$status}");
    }

    public function verify(string $transactionId): array
    {
        if ($this->isBypassMode()) {
            return [
                'success' => true,
                'status' => 'successful',
                'gateway_txn_id' => $transactionId,
                'amount' => 0,
                'currency' => '',
                'message' => 'Bypass mode — auto-verified.',
                'raw_response' => ['bypass' => true],
            ];
        }

        $accessToken = $this->getAccessToken();

        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->timeout(30)
            ->get("{$this->baseUrl}/api/Transactions/GetTransactionStatus", [
                'orderTrackingId' => $transactionId,
            ]);

        $data = $response->json() ?? [];
        $statusCode = (int) ($data['status_code'] ?? 0);

        $status = match ($statusCode) {
            1 => 'successful',
            2, 3 => 'failed',
            default => 'pending',
        };

        $message = match ($status) {
            'successful' => 'Payment completed successfully.',
            'failed' => $data['payment_status_description'] ?? 'Transaction was not completed.',
            'pending' => 'Payment is still pending. Please complete the payment and try again.',
        };

        return [
            'success' => $status === 'successful',
            'status' => $status,
            'gateway_txn_id' => $transactionId,
            'amount' => (float) ($data['amount'] ?? 0),
            'currency' => $data['currency'] ?? '',
            'message' => $message,
            'raw_response' => $data,
        ];
    }

    public function parseWebhookPayload(Request $request): array
    {
        $orderTrackingId = $request->query('OrderTrackingId', '');
        $merchantReference = $request->query('OrderMerchantReference', '');
        $notificationType = $request->query('OrderNotificationType', '');

        return [
            'gateway_txn_id' => $orderTrackingId,
            'our_reference' => $merchantReference,
            'status' => 'pending',
            'amount' => 0,
            'currency' => '',
            'raw_payload' => $request->query(),
        ];
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        return true;
    }

    public function getName(): string
    {
        return 'pesapal';
    }

    public function isRedirectBased(): bool
    {
        return true;
    }

    public function getSupportedCurrencies(): array
    {
        return ['UGX', 'KES', 'TZS', 'USD'];
    }

    public function isEnabled(): bool
    {
        if ($this->isBypassMode()) {
            return true;
        }
        return config('pesapal.enabled', false) === true
            && !empty($this->consumerKey)
            && !empty($this->consumerSecret);
    }

    private function getAccessToken(): string
    {
        $cacheKey = 'pesapal_token_' . config('pesapal.environment');
        $ttl = (int) config('pesapal.token_cache_ttl', 3300);

        return Cache::remember($cacheKey, $ttl, function () {
            $response = Http::acceptJson()
                ->contentType('application/json')
                ->timeout(15)
                ->post("{$this->baseUrl}/api/Auth/RequestToken", [
                    'consumer_key' => $this->consumerKey,
                    'consumer_secret' => $this->consumerSecret,
                ]);

            $data = $response->json() ?? [];
            $rawBody = $response->body();

            if ($response->successful() && !empty($data['token'])) {
                Log::debug('[PesaPal] Access token refreshed.');
                return $data['token'];
            }

            Log::error('[PesaPal] Token request failed', [
                'status' => $response->status(),
                'body' => $rawBody,
                'data' => $data,
            ]);

            $errorMsg = null;
            foreach (['error', 'message', 'error_message'] as $key) {
                $val = $data[$key] ?? null;
                if (is_string($val) && $val !== '') { $errorMsg = $val; break; }
                if (is_array($val)) {
                    foreach (['message', 'error', 'description'] as $sub) {
                        if (isset($val[$sub]) && is_string($val[$sub]) && $val[$sub] !== '') {
                            $errorMsg = $val[$sub]; break 2;
                        }
                    }
                    $errorMsg = json_encode($val); break;
                }
            }

            $detail = $errorMsg ?? "HTTP {$response->status()} (no token in response — check PesaPal credentials)";

            throw new GatewayException(
                "PesaPal token request failed: {$detail}",
                'pesapal',
                $data
            );
        });
    }

    private function registerIpn(string $accessToken): string
    {
        $ipnUrl = config('pesapal.ipn_url') ?? route('billing.gateway.webhook', ['gateway' => 'pesapal']);

        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->timeout(15)
            ->post("{$this->baseUrl}/api/URLSetup/RegisterIPN", [
                'url' => $ipnUrl,
                'ipn_notification_type' => 'GET',
            ]);

        $data = $response->json() ?? [];

        if (!$response->successful() || empty($data['ipn_id'])) {
            throw new GatewayException(
                'PesaPal IPN registration failed: ' . ($data['message'] ?? "HTTP {$response->status()}"),
                'pesapal',
                $data
            );
        }

        Log::info('[PesaPal] IPN registered', ['ipn_id' => $data['ipn_id'], 'url' => $ipnUrl]);

        return $data['ipn_id'];
    }
}
