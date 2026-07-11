<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_asset_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses');
            $table->foreignId('asset_id')->constrained('fixed_assets')->cascadeOnDelete();
            $table->foreignId('from_employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->foreignId('to_employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->string('action');
            $table->text('notes')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_assignments');
    }
};
