<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('location_id')->nullable()->after('business_id');
            $table->foreign('location_id')->references('id')->on('locations')->nullOnDelete();
            $table->index('location_id');
        });

        // Backfill: existing orders inherit the location of their creator, else business default.
        $orders = DB::table('orders')
            ->join('users', 'orders.user_id', '=', 'users.id')
            ->select('orders.id', 'orders.business_id', 'users.location_id as user_location_id')
            ->get();

        foreach ($orders as $order) {
            $locationId = $order->user_location_id;
            if (!$locationId) {
                $locationId = DB::table('locations')
                    ->where('business_id', $order->business_id)
                    ->where('is_default', true)
                    ->value('id');
            }
            DB::table('orders')->where('id', $order->id)->update(['location_id' => $locationId]);
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropIndex('orders_location_id_index');
            $table->dropColumn('location_id');
        });
    }
};