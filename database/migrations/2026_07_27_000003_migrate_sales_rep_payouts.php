<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $oldPayouts = DB::table('sales_rep_payouts')->get();

        foreach ($oldPayouts as $old) {
            DB::table('payouts')->insert([
                'payable_type' => 'App\Models\SalesRep',
                'payable_id' => $old->sales_rep_id,
                'amount' => $old->amount,
                'currency' => 'USD',
                'status' => 'paid',
                'payment_method' => $old->payment_method,
                'notes' => $old->notes,
                'attachments' => $old->attachments,
                'scheduled_at' => null,
                'paid_at' => $old->paid_at ?? $old->created_at,
                'paid_by' => $old->paid_by,
                'created_at' => $old->created_at,
                'updated_at' => $old->updated_at,
            ]);
        }

        Schema::dropIfExists('sales_rep_payouts');
    }

    public function down(): void
    {
        Schema::create('sales_rep_payouts', function ($table) {
            $table->id();
            $table->unsignedBigInteger('sales_rep_id');
            $table->decimal('amount', 14, 2);
            $table->string('payment_method', 30)->nullable();
            $table->text('notes')->nullable();
            $table->json('attachments')->nullable();
            $table->timestamp('paid_at');
            $table->unsignedBigInteger('paid_by')->nullable();
            $table->timestamps();
        });
    }
};
