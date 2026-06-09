<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_notification_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('dispatch_type', 32);
            $table->string('target_kind', 16);
            $table->string('intention', 64)->nullable();
            $table->string('subject', 200)->nullable();
            $table->text('message');
            $table->string('channel', 16)->default('both');
            $table->string('status_from', 32)->nullable();
            $table->string('status_to', 32)->nullable();
            $table->boolean('mark_as_notified')->default(false);
            $table->unsignedInteger('recipient_count')->default(0);
            $table->json('recipients');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['target_kind', 'created_at']);
            $table->index(['dispatch_type', 'created_at']);
            $table->index('actor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_notification_dispatches');
    }
};
