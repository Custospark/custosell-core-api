<?php

use App\Models\Payment;
use App\Models\Sale;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedBigInteger('shift_id')->nullable()->after('recorded_by');
            $table->foreign('shift_id')->references('id')->on('shifts')->nullOnDelete();
            $table->index(['business_id', 'shift_id', 'paid_at']);
        });

        // Backfill sale payments from the sale's shift (best-effort for historical rows).
        if (Schema::hasTable('sales')) {
            Payment::query()
                ->where('payable_type', 'sale')
                ->whereNull('shift_id')
                ->orderBy('id')
                ->chunkById(200, function ($payments): void {
                    foreach ($payments as $payment) {
                        $shiftId = Sale::query()->whereKey($payment->payable_id)->value('shift_id');
                        if ($shiftId) {
                            $payment->forceFill(['shift_id' => $shiftId])->saveQuietly();
                        }
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['shift_id']);
            $table->dropIndex(['business_id', 'shift_id', 'paid_at']);
            $table->dropColumn('shift_id');
        });
    }
};
