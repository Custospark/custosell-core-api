<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipeline_board_announcement_reads', function (Blueprint $table) {
            $table->boolean('is_dismissed')->default(false)->after('read_at');
            $table->timestamp('dismissed_at')->nullable()->after('is_dismissed');
        });

        Schema::create('pipeline_poll_dismissals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained('pipeline_polls')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();

            $table->unique(['poll_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_poll_dismissals');

        Schema::table('pipeline_board_announcement_reads', function (Blueprint $table) {
            $table->dropColumn(['is_dismissed', 'dismissed_at']);
        });
    }
};
