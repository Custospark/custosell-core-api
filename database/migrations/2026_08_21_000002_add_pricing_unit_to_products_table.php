<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a machine-readable selling/pricing unit to products so the UI can
     * offer the right quantity selector (decimal for weight/volume, integer
     * for pieces) and label prices as "per unit" (e.g. UGX 4,000/kg).
     * Existing free-text `unit` stays as the human label.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('pricing_unit', 32)
                ->nullable()
                ->after('unit');
            $table->index(['business_id', 'pricing_unit']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'pricing_unit']);
            $table->dropColumn('pricing_unit');
        });
    }
};