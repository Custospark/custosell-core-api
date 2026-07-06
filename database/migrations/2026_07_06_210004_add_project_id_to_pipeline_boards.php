<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipeline_boards', function (Blueprint $table) {
            $table->unsignedBigInteger('project_id')->nullable()->after('is_archived');

            $table->foreign('project_id')
                ->references('id')
                ->on('projects')
                ->onUpdate('cascade')
                ->onDelete('set null');
        });

        Schema::table('pipeline_leads', function (Blueprint $table) {
            $table->unsignedBigInteger('project_task_id')->nullable()->after('estimate_id');

            $table->foreign('project_task_id')
                ->references('id')
                ->on('project_tasks')
                ->onUpdate('cascade')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('pipeline_leads', function (Blueprint $table) {
            $table->dropForeign(['project_task_id']);
            $table->dropColumn('project_task_id');
        });

        Schema::table('pipeline_boards', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropColumn('project_id');
        });
    }
};
