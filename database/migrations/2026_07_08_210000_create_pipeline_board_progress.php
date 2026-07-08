<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_board_targets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('board_id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->enum('type', ['kpi', 'goal', 'objective', 'key_result']);
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('metric_key', 64);
            $table->decimal('target_value', 16, 4);
            $table->enum('unit', ['count', 'currency', 'percent', 'days']);
            $table->enum('period_type', ['day', 'week', 'month', 'quarter', 'year', 'custom']);
            $table->date('period_start');
            $table->date('period_end');
            $table->enum('scope', ['board', 'member'])->default('board');
            $table->unsignedBigInteger('member_user_id')->nullable();
            $table->unsignedTinyInteger('weight')->default(100);
            $table->enum('status', ['draft', 'active', 'paused', 'completed', 'archived'])->default('active');
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->foreign('board_id')->references('id')->on('pipeline_boards')->cascadeOnDelete();
            $table->foreign('parent_id')->references('id')->on('pipeline_board_targets')->nullOnDelete();
            $table->foreign('member_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['board_id', 'status']);
            $table->index(['board_id', 'period_start', 'period_end']);
            $table->index(['board_id', 'member_user_id']);
        });

        Schema::create('pipeline_board_metric_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('board_id');
            $table->date('snapshot_date');
            $table->string('metric_key', 64);
            $table->enum('scope', ['board', 'member'])->default('board');
            $table->unsignedBigInteger('member_user_id')->nullable();
            $table->decimal('actual_value', 16, 4)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->foreign('board_id')->references('id')->on('pipeline_boards')->cascadeOnDelete();
            $table->foreign('member_user_id')->references('id')->on('users')->nullOnDelete();
            $table->unique(
                ['board_id', 'snapshot_date', 'metric_key', 'scope', 'member_user_id'],
                'pipeline_board_metric_snapshots_unique',
            );
            $table->index(['board_id', 'snapshot_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_board_metric_snapshots');
        Schema::dropIfExists('pipeline_board_targets');
    }
};
