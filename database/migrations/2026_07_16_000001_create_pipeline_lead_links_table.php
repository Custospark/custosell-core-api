<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_lead_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id');
            $table->unsignedBigInteger('linked_lead_id')->nullable();
            $table->unsignedBigInteger('linked_board_id')->nullable();
            $table->string('label', 80)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('lead_id')->references('id')->on('pipeline_leads')->cascadeOnDelete();
            $table->foreign('linked_lead_id')->references('id')->on('pipeline_leads')->cascadeOnDelete();
            $table->foreign('linked_board_id')->references('id')->on('pipeline_boards')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_lead_links');
    }
};
