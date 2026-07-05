<?php

use App\Models\Sale;
use App\Services\PaymentService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        /** @var PaymentService $paymentService */
        $paymentService = app(PaymentService::class);

        Sale::query()
            ->whereIn('payment_status', ['paid', 'partially_paid'])
            ->where('amount_paid', '>', 0)
            ->doesntHave('payments')
            ->orderBy('id')
            ->chunkById(100, function ($sales) use ($paymentService) {
                foreach ($sales as $sale) {
                    $amountPaid = (float) $sale->amount_paid;
                    $paymentService->createInitialSalePayment(
                        $sale,
                        $amountPaid,
                        (string) $sale->payment_method,
                        (int) $sale->user_id,
                        $sale->amount_tendered !== null ? (float) $sale->amount_tendered : $amountPaid,
                        $sale->change_given !== null ? (float) $sale->change_given : null,
                        dispatchAccounting: false,
                    );
                }
            });
    }

    public function down(): void
    {
        // Non-destructive backfill — leave receipt rows in place on rollback.
    }
};
