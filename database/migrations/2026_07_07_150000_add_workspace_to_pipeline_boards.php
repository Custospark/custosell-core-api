<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipeline_boards', function (Blueprint $table) {
            $table->string('workspace', 20)->default('pipeline')->after('project_id');
            $table->index(['business_id', 'workspace']);
        });
    }

    public function down(): void
    {
        Schema::table('pipeline_boards', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'workspace']);
            $table->dropColumn('workspace');
        });
    }
};
