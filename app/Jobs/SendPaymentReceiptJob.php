<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\BillingPayment;
use App\Services\Payment\PaymentReceiptService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendPaymentReceiptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $paymentId,
    ) {}

    public function handle(PaymentReceiptService $receiptService): void
    {
        $payment = BillingPayment::query()->find($this->paymentId);

        if (!$payment || !$payment->isCompleted()) {
            return;
        }

        if ((float) $payment->amount <= 0) {
            return;
        }

        if ($payment->receipt_sent_at !== null) {
            return;
        }

        $sent = $receiptService->email($payment);

        if ($sent) {
            $payment->forceFill(['receipt_sent_at' => now()])->save();
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('SendPaymentReceiptJob failed', [
            'payment_id' => $this->paymentId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
