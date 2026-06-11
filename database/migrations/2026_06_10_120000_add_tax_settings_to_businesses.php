<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('tax_regime', 32)->default('none')->after('tax_id');
            $table->string('jurisdiction', 8)->default('UG')->after('tax_regime');
            $table->decimal('default_vat_rate', 5, 2)->default(18)->after('jurisdiction');
            $table->boolean('prices_include_tax')->default(true)->after('default_vat_rate');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['tax_regime', 'jurisdiction', 'default_vat_rate', 'prices_include_tax']);
        });
    }
};
