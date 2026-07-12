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
 */
class EfrisClient
{
    public function isConfigured(): bool
    {
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
     * @return array{fdn: string, qr: string|null, verification_code: string|null, raw: array<string, mixed>}
     */
    public function submitInvoice(array $payload): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('EFRIS credentials are incomplete. Set TIN, device, and API user/password in Backend .env.');
        }

        $baseUrl = rtrim((string) config('efris.base_url'), '/');
        $url = $baseUrl.'/efrisws/ws/trnsSales/saveSales';

        $envelope = [
            'tin' => config('efris.tin'),
            'deviceNo' => config('efris.device_no'),
            'branchId' => config('efris.branch_id'),
            'data' => $payload,
        ];

        $response = Http::timeout(45)
            ->acceptJson()
            ->withBasicAuth(
                (string) config('efris.api_username'),
                (string) config('efris.api_password'),
            )
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
