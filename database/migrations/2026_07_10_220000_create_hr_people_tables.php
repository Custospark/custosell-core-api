<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_departments')) {
            Schema::create('hr_departments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->text('description')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['business_id', 'sort_order']);
                $table->unique(['business_id', 'name']);
            });
        }

        if (! Schema::hasTable('hr_positions')) {
            Schema::create('hr_positions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->foreignId('department_id')->nullable()->constrained('hr_departments')->nullOnDelete();
                $table->string('title');
                $table->text('description')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['business_id', 'department_id']);
            });
        }

        if (! Schema::hasTable('hr_employees')) {
            Schema::create('hr_employees', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('employee_number');
                $table->string('first_name');
                $table->string('last_name');
                $table->string('email')->nullable();
                $table->string('phone')->nullable();
                $table->foreignId('department_id')->nullable()->constrained('hr_departments')->nullOnDelete();
                $table->foreignId('position_id')->nullable()->constrained('hr_positions')->nullOnDelete();
                $table->foreignId('manager_employee_id')->nullable()->constrained('hr_employees')->nullOnDelete();
                $table->string('employment_type', 32)->default('full_time');
                $table->string('status', 32)->default('onboarding');
                $table->date('hire_date')->nullable();
                $table->date('termination_date')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['business_id', 'employee_number']);
                $table->unique(['business_id', 'user_id']);
                $table->index(['business_id', 'status']);
                $table->index(['business_id', 'department_id']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('hr_employees')) {
            Schema::dropIfExists('hr_employees');
        }
        if (Schema::hasTable('hr_positions')) {
            Schema::dropIfExists('hr_positions');
        }
        if (Schema::hasTable('hr_departments')) {
            Schema::dropIfExists('hr_departments');
        }
    }
};
