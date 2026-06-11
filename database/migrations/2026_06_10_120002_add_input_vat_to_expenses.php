<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('supplier_tin')->nullable()->after('reference');
            $table->string('supplier_invoice_no')->nullable()->after('supplier_tin');
            $table->decimal('vat_amount', 12, 2)->nullable()->after('supplier_invoice_no');
            $table->boolean('vat_claimable')->default(false)->after('vat_amount');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn(['supplier_tin', 'supplier_invoice_no', 'vat_amount', 'vat_claimable']);
        });
    }
};
