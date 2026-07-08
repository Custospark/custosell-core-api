<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_board_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained('pipeline_boards')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('type', ['file', 'link', 'image']);
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('visibility', ['board', 'team', 'members', 'owner_only']);
            $table->string('url', 2048)->nullable();
            $table->string('file_name')->nullable();
            $table->string('file_path')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedInteger('views_count')->default(0);
            $table->unsignedInteger('downloads_count')->default(0);
            $table->timestamps();
        });

        Schema::create('pipeline_board_resource_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_id')->constrained('pipeline_board_resources')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unique(['resource_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_board_resource_members');
        Schema::dropIfExists('pipeline_board_resources');
    }
};
