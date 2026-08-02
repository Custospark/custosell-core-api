<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('location_id')->nullable()->after('business_id');
            $table->foreign('location_id')->references('id')->on('locations')->nullOnDelete();
            $table->index('location_id');
        });

        // Backfill: existing invoices inherit the location of their linked sale when
        // available, otherwise the business default location.
        $invoices = DB::table('invoices')->leftJoin('sales', 'invoices.sale_id', '=', 'sales.id')
            ->select('invoices.id', 'invoices.business_id', 'sales.location_id as sale_location_id')
            ->get();

        foreach ($invoices as $invoice) {
            $locationId = $invoice->sale_location_id;
            if (!$locationId) {
                $locationId = DB::table('locations')
                    ->where('business_id', $invoice->business_id)
                    ->where('is_default', true)
                    ->value('id');
            }
            DB::table('invoices')->where('id', $invoice->id)->update(['location_id' => $locationId]);
        }
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropIndex('invoices_location_id_index');
            $table->dropColumn('location_id');
        });
    }
};