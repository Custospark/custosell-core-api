<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_attendance_events')) {
            Schema::create('hr_attendance_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->string('type', 32);
                $table->timestamp('occurred_at');
                $table->string('source', 64)->nullable();
                $table->text('note')->nullable();
                $table->timestamps();

                $table->index(['business_id', 'employee_id', 'occurred_at']);
            });
        }

        if (! Schema::hasTable('hr_attendance_days')) {
            Schema::create('hr_attendance_days', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->date('work_date');
                $table->string('status', 32)->default('present');
                $table->unsignedInteger('minutes_worked')->default(0);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['business_id', 'employee_id', 'work_date']);
                $table->index(['business_id', 'work_date']);
            });
        }

        if (! Schema::hasTable('hr_leave_types')) {
            Schema::create('hr_leave_types', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('code', 32);
                $table->boolean('paid')->default(true);
                $table->decimal('days_per_year', 8, 2)->default(0);
                $table->boolean('requires_approval')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['business_id', 'code']);
            });
        }

        if (! Schema::hasTable('hr_leave_balances')) {
            Schema::create('hr_leave_balances', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->foreignId('leave_type_id')->constrained('hr_leave_types')->cascadeOnDelete();
                $table->unsignedSmallInteger('year');
                $table->decimal('entitled', 8, 2)->default(0);
                $table->decimal('used', 8, 2)->default(0);
                $table->decimal('pending', 8, 2)->default(0);
                $table->timestamps();

                $table->unique(['employee_id', 'leave_type_id', 'year'], 'hr_leave_balances_unique');
                $table->index(['business_id', 'year']);
            });
        }

        if (! Schema::hasTable('hr_leave_requests')) {
            Schema::create('hr_leave_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->foreignId('leave_type_id')->constrained('hr_leave_types')->cascadeOnDelete();
                $table->date('start_date');
                $table->date('end_date');
                $table->decimal('days', 8, 2);
                $table->string('status', 32)->default('pending');
                $table->text('reason')->nullable();
                $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('review_note')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['business_id', 'status']);
                $table->index(['business_id', 'employee_id']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('hr_leave_requests')) {
            Schema::dropIfExists('hr_leave_requests');
        }
        if (Schema::hasTable('hr_leave_balances')) {
            Schema::dropIfExists('hr_leave_balances');
        }
        if (Schema::hasTable('hr_leave_types')) {
            Schema::dropIfExists('hr_leave_types');
        }
        if (Schema::hasTable('hr_attendance_days')) {
            Schema::dropIfExists('hr_attendance_days');
        }
        if (Schema::hasTable('hr_attendance_events')) {
            Schema::dropIfExists('hr_attendance_events');
        }
    }
};
