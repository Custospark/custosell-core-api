<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('fiscal_status', 32)->default('none')->after('last_emailed_at');
            $table->string('fiscal_fdn', 128)->nullable()->after('fiscal_status');
            $table->text('fiscal_qr')->nullable()->after('fiscal_fdn');
            $table->string('fiscal_verification_code', 128)->nullable()->after('fiscal_qr');
            $table->json('fiscal_payload')->nullable()->after('fiscal_verification_code');
            $table->json('fiscal_response')->nullable()->after('fiscal_payload');
            $table->timestamp('fiscalized_at')->nullable()->after('fiscal_response');
            $table->text('fiscal_last_error')->nullable()->after('fiscalized_at');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('fiscal_status', 32)->default('none')->after('last_emailed_at');
            $table->string('fiscal_fdn', 128)->nullable()->after('fiscal_status');
            $table->text('fiscal_qr')->nullable()->after('fiscal_fdn');
            $table->string('fiscal_verification_code', 128)->nullable()->after('fiscal_qr');
            $table->json('fiscal_payload')->nullable()->after('fiscal_verification_code');
            $table->json('fiscal_response')->nullable()->after('fiscal_payload');
            $table->timestamp('fiscalized_at')->nullable()->after('fiscal_response');
            $table->text('fiscal_last_error')->nullable()->after('fiscalized_at');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn([
                'fiscal_status',
                'fiscal_fdn',
                'fiscal_qr',
                'fiscal_verification_code',
                'fiscal_payload',
                'fiscal_response',
                'fiscalized_at',
                'fiscal_last_error',
            ]);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'fiscal_status',
                'fiscal_fdn',
                'fiscal_qr',
                'fiscal_verification_code',
                'fiscal_payload',
                'fiscal_response',
                'fiscalized_at',
                'fiscal_last_error',
            ]);
        });
    }
};
