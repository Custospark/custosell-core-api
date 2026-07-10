<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_onboarding_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('tasks_json')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id']);
        });

        Schema::create('hr_onboarding_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('hr_onboarding_templates')->nullOnDelete();
            $table->string('title');
            $table->string('status', 32)->default('pending');
            $table->date('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'employee_id', 'status']);
        });

        Schema::create('hr_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('reviewer_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('period_label');
            $table->string('status', 32)->default('draft');
            $table->decimal('rating', 4, 2)->nullable();
            $table->text('strengths')->nullable();
            $table->text('improvements')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'employee_id']);
            $table->index(['business_id', 'status']);
        });

        Schema::create('hr_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->json('meta_json')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        // Global Uganda statutory defaults (business_id null = system-wide).
        if (Schema::hasTable('hr_statutory_rate_sets')) {
            $exists = DB::table('hr_statutory_rate_sets')
                ->whereNull('business_id')
                ->where('country', 'UG')
                ->exists();

            if (! $exists) {
                DB::table('hr_statutory_rate_sets')->insert([
                    'business_id' => null,
                    'country' => 'UG',
                    'effective_from' => '2024-07-01',
                    'paye_brackets_json' => json_encode([
                        ['up_to' => 235000, 'rate' => 0, 'base_tax' => 0],
                        ['up_to' => 335000, 'rate' => 0.10, 'base_tax' => 0],
                        ['up_to' => 410000, 'rate' => 0.20, 'base_tax' => 10000],
                        ['up_to' => 10000000, 'rate' => 0.30, 'base_tax' => 25000],
                        ['up_to' => null, 'rate' => 0.40, 'base_tax' => 2902500],
                    ]),
                    'nssf_employee_rate' => 0.05,
                    'nssf_employer_rate' => 0.10,
                    'notes' => 'Uganda PAYE monthly bands (2024-ish) + NSSF 5%/10%.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_audit_logs');
        Schema::dropIfExists('hr_reviews');
        Schema::dropIfExists('hr_onboarding_tasks');
        Schema::dropIfExists('hr_onboarding_templates');
    }
};
