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

        DB::statement('ALTER TABLE pipeline_attachments MODIFY file_path VARCHAR(500) NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE pipeline_attachments MODIFY file_path VARCHAR(500) NOT NULL');
        Schema::table('pipeline_attachments', function (Blueprint $table) {
            $table->dropColumn(['type', 'link_url']);
        });
    }
};
