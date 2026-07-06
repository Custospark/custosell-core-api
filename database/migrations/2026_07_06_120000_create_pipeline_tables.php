<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_boards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('created_by');
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->enum('visibility', ['team', 'private', 'shared'])->default('team');
            $table->string('cover_color', 32)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_archived')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['business_id', 'is_archived']);
        });

        Schema::create('pipeline_board_members', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('board_id');
            $table->unsignedBigInteger('user_id');
            $table->enum('role', ['viewer', 'editor'])->default('editor');
            $table->timestamps();

            $table->foreign('board_id')->references('id')->on('pipeline_boards')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['board_id', 'user_id']);
        });

        Schema::create('pipeline_stages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('board_id');
            $table->string('name', 120);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('color', 32)->nullable();
            $table->boolean('is_won')->default(false);
            $table->boolean('is_lost')->default(false);
            $table->unsignedSmallInteger('rotting_days')->nullable();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->foreign('board_id')->references('id')->on('pipeline_boards')->cascadeOnDelete();
            $table->index(['board_id', 'sort_order']);
        });

        Schema::create('pipeline_sources', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->string('name', 120);
            $table->boolean('is_system')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->index('business_id');
        });

        Schema::create('pipeline_leads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('board_id');
            $table->unsignedBigInteger('stage_id');
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('converted_customer_id')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('contact_name', 255)->nullable();
            $table->string('contact_email', 255)->nullable();
            $table->string('contact_phone', 50)->nullable();
            $table->decimal('estimated_value', 14, 2)->nullable();
            $table->string('currency', 8)->default('UGX');
            $table->enum('status', ['open', 'won', 'lost', 'converted', 'archived'])->default('open');
            $table->decimal('position', 12, 4)->default(0);
            $table->date('expected_close_date')->nullable();
            $table->timestamp('won_at')->nullable();
            $table->timestamp('lost_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->string('lost_reason', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->foreign('board_id')->references('id')->on('pipeline_boards')->cascadeOnDelete();
            $table->foreign('stage_id')->references('id')->on('pipeline_stages')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            $table->foreign('converted_customer_id')->references('id')->on('customers')->nullOnDelete();
            $table->foreign('source_id')->references('id')->on('pipeline_sources')->nullOnDelete();
            $table->index(['business_id', 'board_id', 'stage_id']);
            $table->index(['business_id', 'assigned_to']);
            $table->index(['business_id', 'status']);
        });

        Schema::create('pipeline_lead_activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('lead_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->enum('type', ['note', 'call', 'email', 'meeting', 'stage_change', 'system'])->default('note');
            $table->text('body')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->foreign('lead_id')->references('id')->on('pipeline_leads')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['lead_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_lead_activities');
        Schema::dropIfExists('pipeline_leads');
        Schema::dropIfExists('pipeline_sources');
        Schema::dropIfExists('pipeline_stages');
        Schema::dropIfExists('pipeline_board_members');
        Schema::dropIfExists('pipeline_boards');
    }
};
