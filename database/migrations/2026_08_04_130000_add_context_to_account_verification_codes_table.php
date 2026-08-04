<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_verification_codes', function (Blueprint $table) {
            $table->json('context')->nullable()->after('code_hash');
        });
    }

    public function down(): void
    {
        Schema::table('account_verification_codes', function (Blueprint $table) {
            $table->dropColumn('context');
        });
    }
};