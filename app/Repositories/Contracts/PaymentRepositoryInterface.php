<?php

namespace App\Repositories\Contracts;

use App\Models\BillingPayment;
use Illuminate\Database\Eloquent\Collection;

interface PaymentRepositoryInterface
{
    public function all(): Collection;
    public function find(int $id): ?BillingPayment;
    public function findByBusiness(int $businessId): Collection;
    public function findByGatewayTransactionId(string $gatewayTransactionId): ?BillingPayment;
    public function findByTransactionReference(string $reference): ?BillingPayment;
    public function create(array $data): BillingPayment;
    public function update(BillingPayment $payment, array $data): BillingPayment;
    public function delete(BillingPayment $payment): bool;
}
