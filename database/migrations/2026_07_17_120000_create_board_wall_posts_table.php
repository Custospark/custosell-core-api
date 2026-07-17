<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('board_wall_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('board_id')->nullable()->constrained('pipeline_boards')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->string('type'); // quote, shoutout, performer, milestone
            $table->string('title')->nullable();
            $table->text('content');
            $table->string('author_name')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->boolean('pinned')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_wall_posts');
    }
};
