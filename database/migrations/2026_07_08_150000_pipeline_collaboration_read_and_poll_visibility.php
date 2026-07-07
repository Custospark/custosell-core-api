<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipeline_polls', function (Blueprint $table) {
            $table->enum('results_visibility', ['team', 'creator_only'])->default('team')->after('allow_multiple');
        });

        Schema::create('pipeline_board_announcement_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')->constrained('pipeline_board_announcements')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['announcement_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_board_announcement_reads');

        Schema::table('pipeline_polls', function (Blueprint $table) {
            $table->dropColumn('results_visibility');
        });
    }
};
