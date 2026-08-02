<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->unsignedBigInteger('to_location_id')->nullable()->after('location_id');
            $table->foreign('to_location_id')->references('id')->on('locations')->nullOnDelete();
            $table->index('to_location_id');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['to_location_id']);
            $table->dropIndex(['to_location_id']);
            $table->dropColumn('to_location_id');
        });
    }
};