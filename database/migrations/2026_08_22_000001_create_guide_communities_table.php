<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Company-wide Custosell communities (WhatsApp, Telegram, Discord, etc.)
     * that any authenticated Custosell user can join. Managed by platform
     * admins under Guide settings; surfaced to users in the Communities
     * component (auth only). A single global list - not per-business.
     */
    public function up(): void
    {
        Schema::create('guide_communities', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->string('platform', 32)->default('other');
            $table->string('url', 2048);
            $table->string('icon', 64)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_published')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guide_communities');
    }
};