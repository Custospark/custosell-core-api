<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Idempotent counterpart to 2026_07_08_160000_pipeline_collaboration_dismissals.
     * Reruns safely on environments where the columns/table already exist but the
     * original migration was never recorded (e.g. interrupted or partial applies).
     * No-op when everything is already present.
     */
    public function up(): void
    {
        Schema::table('pipeline_board_announcement_reads', function (Blueprint $table) {
            if (! Schema::hasColumn('pipeline_board_announcement_reads', 'is_dismissed')) {
                $table->boolean('is_dismissed')->default(false)->after('read_at');
            }
            if (! Schema::hasColumn('pipeline_board_announcement_reads', 'dismissed_at')) {
                $table->timestamp('dismissed_at')->nullable()->after('is_dismissed');
            }
        });

        if (! Schema::hasTable('pipeline_poll_dismissals')) {
            Schema::create('pipeline_poll_dismissals', function (Blueprint $table) {
                $table->id();
                $table->foreignId('poll_id')->constrained('pipeline_polls')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->timestamp('dismissed_at')->nullable();
                $table->timestamps();

                $table->unique(['poll_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('pipeline_poll_dismissals')) {
            Schema::drop('pipeline_poll_dismissals');
        }

        Schema::table('pipeline_board_announcement_reads', function (Blueprint $table) {
            if (Schema::hasColumn('pipeline_board_announcement_reads', 'dismissed_at')) {
                $table->dropColumn('dismissed_at');
            }
            if (Schema::hasColumn('pipeline_board_announcement_reads', 'is_dismissed')) {
                $table->dropColumn('is_dismissed');
            }
        });
    }
};