<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipeline_leads', function (Blueprint $table) {
            $table->enum('card_type', ['lead', 'card'])->default('lead')->after('title');
            $table->date('due_date')->nullable()->after('expected_close_date');
            $table->date('start_date')->nullable()->after('due_date');
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->nullable()->after('start_date');
        });

        Schema::create('pipeline_labels', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('board_id')->nullable();
            $table->string('name', 80);
            $table->string('color', 32)->default('#6366f1');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->foreign('board_id')->references('id')->on('pipeline_boards')->cascadeOnDelete();
            $table->index(['business_id', 'board_id']);
        });

        Schema::create('pipeline_lead_labels', function (Blueprint $table) {
            $table->unsignedBigInteger('lead_id');
            $table->unsignedBigInteger('label_id');
            $table->primary(['lead_id', 'label_id']);

            $table->foreign('lead_id')->references('id')->on('pipeline_leads')->cascadeOnDelete();
            $table->foreign('label_id')->references('id')->on('pipeline_labels')->cascadeOnDelete();
        });

        Schema::create('pipeline_checklists', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id');
            $table->string('title', 255)->default('Checklist');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('lead_id')->references('id')->on('pipeline_leads')->cascadeOnDelete();
            $table->index('lead_id');
        });

        Schema::create('pipeline_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('checklist_id');
            $table->string('title', 500);
            $table->boolean('is_done')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('checklist_id')->references('id')->on('pipeline_checklists')->cascadeOnDelete();
            $table->index(['checklist_id', 'sort_order']);
        });

        Schema::create('pipeline_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('file_name', 255);
            $table->string('file_path', 500);
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->timestamps();

            $table->foreign('lead_id')->references('id')->on('pipeline_leads')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index('lead_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_attachments');
        Schema::dropIfExists('pipeline_checklist_items');
        Schema::dropIfExists('pipeline_checklists');
        Schema::dropIfExists('pipeline_lead_labels');
        Schema::dropIfExists('pipeline_labels');

        Schema::table('pipeline_leads', function (Blueprint $table) {
            $table->dropColumn(['card_type', 'due_date', 'start_date', 'priority']);
        });
    }
};
