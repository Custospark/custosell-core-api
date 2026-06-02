<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('business_id')->nullable();
            $table->unsignedBigInteger('role_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('phone', 50)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->softDeletes();

            $table->index('business_id');
            $table->index('role_id');
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['business_id']);
            $table->dropIndex(['role_id']);
            $table->dropIndex(['created_by']);
            $table->dropColumn(['business_id', 'role_id', 'is_active', 'phone', 'created_by', 'deleted_at']);
        });
    }
};
