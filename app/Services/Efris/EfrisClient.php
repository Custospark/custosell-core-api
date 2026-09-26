<?php

declare(strict_types=1);

namespace App\Services\Efris;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Thin HTTP client for URA EFRIS system-to-system API.
 *
 * Auth + encryption details follow URA T-code docs; this client keeps the
 * transport and credential checks in one place so EfrisService stays domain-focused.
 *
 * Credentials arrive per business/location from the fiscal vault when present,
 * otherwise the deployment-global .env set is used (pilot backward compat).
 */
class EfrisClient
{
    /** @param array<string, mixed>|null $credentials Vault row or null for global .env. */
    public function isConfigured(?array $credentials = null): bool
    {
        if ($credentials !== null) {
            return ((string) ($credentials['tin'] ?? '')) !== ''
                && ((string) ($credentials['device_no'] ?? '')) !== ''
                && ((string) ($credentials['api_username'] ?? '')) !== ''
                && ((string) ($credentials['api_password'] ?? '')) !== '';
        }

        $tin = (string) config('efris.tin', '');
        $device = (string) config('efris.device_no', '');
        $user = (string) config('efris.api_username', '');
        $pass = (string) config('efris.api_password', '');

        return $tin !== '' && $device !== '' && $user !== '' && $pass !== '';
    }

    /**
     * Submit a fiscal invoice/receipt payload to URA.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $credentials Vault row or null for global .env.
     * @return array{fdn: string, qr: string|null, verification_code: string|null, raw: array<string, mixed>}
     */
    public function submitInvoice(array $payload, ?array $credentials = null): array
    {
        if (! $this->isConfigured($credentials)) {
            throw new RuntimeException('EFRIS credentials are incomplete. Save per-business credentials or set TIN, device, and API user/password in Backend .env.');
        }

        $tin = $credentials !== null ? (string) $credentials['tin'] : (string) config('efris.tin');
        $deviceNo = $credentials !== null ? (string) $credentials['device_no'] : (string) config('efris.device_no');
        $branchId = $credentials !== null ? ($credentials['branch_id'] ?? null) : config('efris.branch_id');
        $apiUser = $credentials !== null ? (string) $credentials['api_username'] : (string) config('efris.api_username');
        $apiPass = $credentials !== null ? (string) $credentials['api_password'] : (string) config('efris.api_password');

        $baseUrl = rtrim((string) config('efris.base_url'), '/');
        $url = $baseUrl.'/efrisws/ws/trnsSales/saveSales';

        $envelope = [
            'tin' => $tin,
            'deviceNo' => $deviceNo,
            'branchId' => $branchId,
            'data' => $payload,
        ];

        $response = Http::timeout(45)
            ->acceptJson()
            ->withBasicAuth($apiUser, $apiPass)
            ->post($url, $envelope);

        if (!$response->successful()) {
            Log::warning('EFRIS HTTP error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException('EFRIS request failed (HTTP '.$response->status().').');
        }

        /** @var array<string, mixed> $json */
        $json = $response->json() ?? [];
        $fdn = (string) (
            data_get($json, 'data.basicInformation.invoiceNo')
            ?? data_get($json, 'data.invoiceNo')
            ?? data_get($json, 'invoiceNo')
            ?? data_get($json, 'fdn')
            ?? ''
        );

        if ($fdn === '') {
            $msg = (string) (data_get($json, 'returnMessage') ?? data_get($json, 'message') ?? 'Missing fiscal document number in response');
            throw new RuntimeException('EFRIS rejected or incomplete response: '.$msg);
        }

        return [
            'fdn' => $fdn,
            'qr' => data_get($json, 'data.summary.qrCode')
                ?? data_get($json, 'data.qrCode')
                ?? data_get($json, 'qrCode'),
            'verification_code' => data_get($json, 'data.basicInformation.antiFakeCode')
                ?? data_get($json, 'data.verificationCode')
                ?? data_get($json, 'verificationCode'),
            'raw' => $json,
        ];
    }
}
