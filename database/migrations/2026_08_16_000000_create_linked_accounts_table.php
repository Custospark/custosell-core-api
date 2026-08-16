<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('linked_accounts', function (Blueprint $table) {
            $table->id();
            // The user who linked the account (owner of the switch list).
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            // The account that was linked (the switch target).
            $table->foreignId('linked_user_id')->constrained('users')->cascadeOnDelete();
            // 'primary' = default account, 'secondary' = the rest.
            $table->string('relation', 20)->default('secondary');
            $table->timestamps();

            $table->unique(['owner_user_id', 'linked_user_id']);
            $table->index('owner_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('linked_accounts');
    }
};
