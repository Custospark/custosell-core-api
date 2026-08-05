<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->nullable()->constrained()->nullOnDelete();
            $table->string('endpoint');
            $table->string('public_key', 512)->comment('p256dh client public key (base64url)');
            $table->string('auth_secret', 512)->comment('auth secret (base64url)');
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'endpoint']);
            $table->index(['business_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};