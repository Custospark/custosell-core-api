<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Generalized board automation rules: "When [trigger], if [conditions],
     * then [actions]". Trigger/conditions/actions are stored as structured
     * JSON so the engine can grow without new columns. A scheduled cron
     * command scans rules and fires the matching cards idempotently.
     */
    public function up(): void
    {
        Schema::create('pipeline_automation_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('board_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('name', 255);
            $table->json('trigger');
            $table->json('conditions')->nullable();
            $table->json('actions');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('run_count')->default(0);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->foreign('board_id')->references('id')->on('pipeline_boards')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['business_id', 'board_id', 'is_active']);
            $table->index(['board_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_automation_rules');
    }
};