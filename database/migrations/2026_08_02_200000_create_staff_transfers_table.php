<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_transfers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('from_location_id')->nullable();
            $table->unsignedBigInteger('to_location_id');
            $table->unsignedBigInteger('transferred_by')->nullable();
            $table->string('transfer_type', 30)->default('permanent');
            $table->string('status', 30)->default('completed');
            $table->boolean('approval_required')->default(false);
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->date('effective_at')->nullable();
            $table->date('end_at')->nullable();
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('old_role_id')->nullable();
            $table->unsignedBigInteger('new_role_id')->nullable();
            $table->unsignedBigInteger('old_shift_id')->nullable();
            $table->unsignedBigInteger('new_shift_id')->nullable();
            $table->decimal('old_salary', 15, 2)->nullable();
            $table->decimal('new_salary', 15, 2)->nullable();
            $table->string('old_employment_type', 40)->nullable();
            $table->string('new_employment_type', 40)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('from_location_id')->references('id')->on('locations')->nullOnDelete();
            $table->foreign('to_location_id')->references('id')->on('locations')->cascadeOnDelete();
            $table->foreign('transferred_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('old_role_id')->references('id')->on('roles')->nullOnDelete();
            $table->foreign('new_role_id')->references('id')->on('roles')->nullOnDelete();
            $table->foreign('old_shift_id')->references('id')->on('shifts')->nullOnDelete();
            $table->foreign('new_shift_id')->references('id')->on('shifts')->nullOnDelete();

            $table->index('business_id');
            $table->index('user_id');
            $table->index('to_location_id');
            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'transfer_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_transfers');
    }
};
