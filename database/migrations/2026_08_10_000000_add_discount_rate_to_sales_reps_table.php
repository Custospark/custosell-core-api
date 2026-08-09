<?php

use App\Enums\Billing\CommissionType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_reps', function (Blueprint $table) {
            $table->decimal('discount_rate', 8, 2)
                ->default(20)
                ->after('commission_rate')
                ->comment('Percentage the referee gets off — independent of the rep commission.');
        });

        // One-off migration of existing reps into the approved safe zone
        // (company keeps the largest share): discount_rate=20, commission_rate=30.
        DB::table('sales_reps')
            ->where('commission_type', CommissionType::PERCENTAGE->value)
            ->update([
                'discount_rate' => 20,
                'commission_rate' => 30,
            ]);

        DB::table('sales_reps')
            ->where('commission_type', '!=', CommissionType::PERCENTAGE->value)
            ->update(['discount_rate' => 20]);
    }

    public function down(): void
    {
        Schema::table('sales_reps', function (Blueprint $table) {
            $table->dropColumn('discount_rate');
        });
    }
};