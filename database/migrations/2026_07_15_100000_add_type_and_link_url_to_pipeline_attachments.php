<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipeline_attachments', function (Blueprint $table) {
            $table->string('type', 20)->default('file')->after('user_id');
            $table->string('link_url', 2048)->nullable()->after('file_size');
        });

        Schema::table('pipeline_attachments', function (Blueprint $table) {
            $table->string('file_path', 500)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('pipeline_attachments', function (Blueprint $table) {
            $table->string('file_path', 500)->nullable(false)->change();
        });
        Schema::table('pipeline_attachments', function (Blueprint $table) {
            $table->dropColumn(['type', 'link_url']);
        });
    }
};
