<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('board_booking_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('board_id')->unique();
            $table->boolean('enabled')->default(false);
            $table->string('token', 64)->unique();
            $table->json('available_days')->nullable();
            $table->string('start_time', 5)->default('09:00');
            $table->string('end_time', 5)->default('17:00');
            $table->unsignedSmallInteger('slot_duration')->default(30);
            $table->unsignedSmallInteger('max_slots_per_day')->default(10);
            $table->string('meeting_title_prefix', 120)->nullable();
            $table->unsignedBigInteger('target_stage_id')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->foreign('board_id')->references('id')->on('pipeline_boards')->cascadeOnDelete();
            $table->foreign('target_stage_id')->references('id')->on('pipeline_stages')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_booking_settings');
    }
};
