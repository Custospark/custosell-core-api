<?php

namespace App\Services\Billing;

use App\Models\BillingPayment;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Services\Contracts\PaymentServiceInterface;
use RuntimeException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentService implements PaymentServiceInterface
{
    public function __construct(
        protected PaymentRepositoryInterface $paymentRepository,
    ) {}

    public function getAll(): Collection
    {
        return $this->paymentRepository->all();
    }

    public function getById(int $id): ?BillingPayment
    {
        return $this->paymentRepository->find($id);
    }

    public function getByBusiness(int $businessId): Collection
    {
        return $this->paymentRepository->findByBusiness($businessId);
    }

    public function createPending(array $data): BillingPayment
    {
        return $this->paymentRepository->create(array_merge($data, [
            'status' => 'pending',
        ]));
    }

    public function complete(BillingPayment $payment, ?string $gatewayResponse = null): BillingPayment
    {
        if ($payment->isCompleted()) {
            throw new RuntimeException("Payment #{$payment->id} is already completed");
        }

        return DB::transaction(function () use ($payment, $gatewayResponse) {
            $data = [
                'status' => 'completed',
                'paid_at' => $payment->paid_at ?? now(),
            ];

            if ($gatewayResponse) {
                $existing = $payment->gateway_response ?? [];
                $data['gateway_response'] = array_merge($existing, ['completion' => $gatewayResponse]);
            }

            return $this->paymentRepository->update($payment, $data);
        });
    }

    public function fail(BillingPayment $payment, string $reason): BillingPayment
    {
        if ($payment->isCompleted()) {
            throw new RuntimeException("Cannot fail completed payment #{$payment->id}");
        }

        return DB::transaction(function () use ($payment, $reason) {
            $data = [
                'status' => 'failed',
                'rejection_reason' => $reason,
            ];

            return $this->paymentRepository->update($payment, $data);
        });
    }

    public function refund(BillingPayment $payment): BillingPayment
    {
        if (!$payment->isCompleted()) {
            throw new RuntimeException("Cannot refund payment #{$payment->id} that is not completed");
        }

        return DB::transaction(function () use ($payment) {
            $data = [
                'status' => 'refunded',
            ];

            Log::info('[PaymentService] Payment refunded', [
                'payment_id' => $payment->id,
                'amount' => $payment->amount,
            ]);

            return $this->paymentRepository->update($payment, $data);
        });
    }
}
