<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_board_meta_fields', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('board_id');
            $table->string('name', 120);
            $table->string('type', 20); // text, number, date, select, multi_select
            $table->json('options')->nullable(); // ["Option A", "Option B"] for select/multi_select
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('required')->default(false);
            $table->timestamps();

            $table->foreign('board_id')->references('id')->on('pipeline_boards')->cascadeOnDelete();
        });

        Schema::create('pipeline_lead_meta_values', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id');
            $table->unsignedBigInteger('meta_field_id');
            $table->text('value')->nullable();
            $table->timestamps();

            $table->foreign('lead_id')->references('id')->on('pipeline_leads')->cascadeOnDelete();
            $table->foreign('meta_field_id')->references('id')->on('pipeline_board_meta_fields')->cascadeOnDelete();
            $table->unique(['lead_id', 'meta_field_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_lead_meta_values');
        Schema::dropIfExists('pipeline_board_meta_fields');
    }
};
