<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_activity_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained('pipeline_lead_activities')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('reaction', ['like', 'dislike']);
            $table->timestamps();

            $table->unique(['activity_id', 'user_id']);
        });

        Schema::create('pipeline_lead_assignees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('pipeline_leads')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['lead_id', 'user_id']);
        });

        Schema::create('pipeline_board_announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('board_id')->constrained('pipeline_boards')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('body');
            $table->boolean('is_pinned')->default(false);
            $table->timestamps();
        });

        Schema::create('pipeline_polls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('board_id')->constrained('pipeline_boards')->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('pipeline_leads')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('question');
            $table->timestamp('closes_at')->nullable();
            $table->boolean('allow_multiple')->default(false);
            $table->timestamps();
        });

        Schema::create('pipeline_poll_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained('pipeline_polls')->cascadeOnDelete();
            $table->string('label');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('pipeline_poll_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained('pipeline_polls')->cascadeOnDelete();
            $table->foreignId('option_id')->constrained('pipeline_poll_options')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['poll_id', 'user_id', 'option_id']);
        });

        Schema::create('pipeline_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('pipeline_leads')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('remind_at');
            $table->string('message')->nullable();
            $table->enum('channel', ['in_app', 'email', 'both'])->default('both');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_reminders');
        Schema::dropIfExists('pipeline_poll_votes');
        Schema::dropIfExists('pipeline_poll_options');
        Schema::dropIfExists('pipeline_polls');
        Schema::dropIfExists('pipeline_board_announcements');
        Schema::dropIfExists('pipeline_lead_assignees');
        Schema::dropIfExists('pipeline_activity_reactions');
    }
};
