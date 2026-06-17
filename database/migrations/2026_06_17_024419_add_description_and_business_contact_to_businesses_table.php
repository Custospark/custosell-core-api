<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->text('description')->nullable()->after('business_type');
            $table->string('business_email', 255)->nullable()->after('description');
            $table->string('business_phone', 50)->nullable()->after('business_email');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['description', 'business_email', 'business_phone']);
        });
    }
};
