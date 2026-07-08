<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipeline_board_messages', function (Blueprint $table) {
            $table->boolean('is_pinned')->default(false)->after('body');
            $table->timestamp('pinned_at')->nullable()->after('is_pinned');
            $table->foreignId('pinned_by')->nullable()->after('pinned_at')->constrained('users')->nullOnDelete();
        });

        Schema::create('pipeline_board_message_mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('pipeline_board_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unique(['message_id', 'user_id']);
        });

        Schema::create('pipeline_board_message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('pipeline_board_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('file_name');
            $table->string('file_path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->timestamps();
        });

        Schema::create('pipeline_board_activity_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('board_id')->constrained('pipeline_boards')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 64);
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('entity_type', 64)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['board_id', 'created_at']);
        });

        Schema::create('pipeline_board_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('workspace', ['pipeline', 'estimates'])->default('pipeline');
            $table->json('stages')->nullable();
            $table->json('labels')->nullable();
            $table->json('resources')->nullable();
            $table->json('automations')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::table('pipeline_board_message_reactions', function (Blueprint $table) {
            $table->string('reaction', 32)->change();
        });

        Schema::create('pipeline_board_automations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('board_id')->constrained('pipeline_boards')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->enum('trigger_type', ['stage_entered', 'status_won', 'status_lost']);
            $table->foreignId('trigger_stage_id')->nullable()->constrained('pipeline_stages')->nullOnDelete();
            $table->enum('action_type', ['conversation_post', 'conversation_notify']);
            $table->text('action_body');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('pipeline_board_message_reactions', function (Blueprint $table) {
            $table->enum('reaction', ['like', 'dislike'])->change();
        });

        Schema::dropIfExists('pipeline_board_automations');
        Schema::dropIfExists('pipeline_board_templates');
        Schema::dropIfExists('pipeline_board_activity_events');
        Schema::dropIfExists('pipeline_board_message_attachments');
        Schema::dropIfExists('pipeline_board_message_mentions');
        Schema::table('pipeline_board_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pinned_by');
            $table->dropColumn(['is_pinned', 'pinned_at']);
        });
    }
};
