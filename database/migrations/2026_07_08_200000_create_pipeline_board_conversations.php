<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_board_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('board_id')->constrained('pipeline_boards')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('pipeline_board_messages')->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pipeline_board_message_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('pipeline_board_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('reaction', ['like', 'dislike']);
            $table->timestamps();
            $table->unique(['message_id', 'user_id']);
        });

        Schema::create('pipeline_board_conversation_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained('pipeline_boards')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('last_read_message_id')->nullable()->constrained('pipeline_board_messages')->nullOnDelete();
            $table->timestamp('last_read_at')->nullable();
            $table->timestamps();
            $table->unique(['board_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_board_conversation_reads');
        Schema::dropIfExists('pipeline_board_message_reactions');
        Schema::dropIfExists('pipeline_board_messages');
    }
};
