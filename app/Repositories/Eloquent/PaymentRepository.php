<?php

namespace App\Repositories\Eloquent;

use App\Models\BillingPayment;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class PaymentRepository implements PaymentRepositoryInterface
{
    public function all(): Collection
    {
        return BillingPayment::with(['business', 'subscription.plan'])->get();
    }

    public function find(int $id): ?BillingPayment
    {
        return BillingPayment::with(['business', 'subscription.plan'])->find($id);
    }

    public function findByBusiness(int $businessId): Collection
    {
        return BillingPayment::where('business_id', $businessId)
            ->with('subscription.plan')
            ->orderByDesc('id')
            ->get();
    }

    public function findByGatewayTransactionId(string $gatewayTransactionId): ?BillingPayment
    {
        return BillingPayment::where('gateway_transaction_id', $gatewayTransactionId)->first();
    }

    public function findByTransactionReference(string $reference): ?BillingPayment
    {
        return BillingPayment::where('transaction_reference', $reference)->first();
    }

    public function create(array $data): BillingPayment
    {
        return BillingPayment::create($data);
    }

    public function update(BillingPayment $payment, array $data): BillingPayment
    {
        $payment->update($data);
        return $payment->fresh();
    }

    public function delete(BillingPayment $payment): bool
    {
        return $payment->delete();
    }
}
