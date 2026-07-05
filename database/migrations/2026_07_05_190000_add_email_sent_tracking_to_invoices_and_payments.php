<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedInteger('email_sent_count')->default(0)->after('notes');
            $table->timestamp('last_emailed_at')->nullable()->after('email_sent_count');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedInteger('email_sent_count')->default(0)->after('attachment_path');
            $table->timestamp('last_emailed_at')->nullable()->after('email_sent_count');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['email_sent_count', 'last_emailed_at']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['email_sent_count', 'last_emailed_at']);
        });
    }
};
