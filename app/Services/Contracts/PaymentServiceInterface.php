<?php

namespace App\Services\Contracts;

use App\Models\BillingPayment;
use Illuminate\Database\Eloquent\Collection;

interface PaymentServiceInterface
{
    public function getAll(): Collection;
    public function getById(int $id): ?BillingPayment;
    public function getByBusiness(int $businessId): Collection;
    public function createPending(array $data): BillingPayment;
    public function complete(BillingPayment $payment, ?string $gatewayResponse = null): BillingPayment;
    public function fail(BillingPayment $payment, string $reason): BillingPayment;
    public function refund(BillingPayment $payment): BillingPayment;
}
