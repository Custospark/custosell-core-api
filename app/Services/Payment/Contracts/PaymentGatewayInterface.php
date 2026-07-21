<?php

namespace App\Services\Payment\Contracts;

use Illuminate\Http\Request;

interface PaymentGatewayInterface
{
    public function initiate(array $payload): array;
    public function verify(string $transactionId): array;
    public function parseWebhookPayload(Request $request): array;
    public function verifyWebhookSignature(Request $request): bool;
    public function getName(): string;
    public function isEnabled(): bool;
    public function getSupportedCurrencies(): array;
    public function isRedirectBased(): bool;
}
