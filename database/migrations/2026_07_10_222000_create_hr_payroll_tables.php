<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payroll liability COA note:
 * DefaultAccountingTemplateSeeder already uses 2104 (Short-term Loans),
 * 2105 (Dividends Payable), and 2106 (Deferred Revenue). Do not overwrite those.
 * Pay-run posting uses 6101 Salaries & Wages (debit) and 2103 Accrued Expenses
 * (credit) when present; otherwise stores intended journal lines in posting_note.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_salary_structures')) {
            Schema::create('hr_salary_structures', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('currency', 8)->default('UGX');
                $table->timestamps();
                $table->softDeletes();
                $table->index(['business_id']);
            });
        }

        if (! Schema::hasTable('hr_employee_compensations')) {
            Schema::create('hr_employee_compensations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->foreignId('structure_id')->nullable()->constrained('hr_salary_structures')->nullOnDelete();
                $table->decimal('basic_salary', 15, 2);
                $table->json('allowances_json')->nullable();
                $table->json('deductions_json')->nullable();
                $table->date('effective_from');
                $table->timestamps();
                $table->softDeletes();
                $table->index(['business_id', 'employee_id', 'effective_from'], 'hr_comp_biz_emp_eff_idx');
            });
        }

        if (! Schema::hasTable('hr_statutory_rate_sets')) {
            Schema::create('hr_statutory_rate_sets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->nullable()->constrained()->cascadeOnDelete();
                $table->string('country', 8)->default('UG');
                $table->date('effective_from');
                $table->json('paye_brackets_json');
                $table->decimal('nssf_employee_rate', 8, 4)->default(0.05);
                $table->decimal('nssf_employer_rate', 8, 4)->default(0.10);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->index(['country', 'effective_from']);
            });
        }

        if (! Schema::hasTable('hr_pay_runs')) {
            Schema::create('hr_pay_runs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->date('period_start');
                $table->date('period_end');
                $table->string('status', 32)->default('draft');
                $table->foreignId('posted_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
                $table->timestamp('posted_at')->nullable();
                $table->text('posting_note')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['business_id', 'status'], 'hr_pay_runs_biz_status_idx');
                $table->index(['business_id', 'period_start', 'period_end'], 'hr_pay_runs_biz_period_idx');
            });
        }

        if (! Schema::hasTable('hr_pay_run_lines')) {
            Schema::create('hr_pay_run_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->foreignId('pay_run_id')->constrained('hr_pay_runs')->cascadeOnDelete();
                $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->decimal('gross', 15, 2)->default(0);
                $table->decimal('paye', 15, 2)->default(0);
                $table->decimal('nssf_employee', 15, 2)->default(0);
                $table->decimal('nssf_employer', 15, 2)->default(0);
                $table->decimal('other_deductions', 15, 2)->default(0);
                $table->decimal('net', 15, 2)->default(0);
                $table->json('breakdown_json')->nullable();
                $table->timestamps();
                $table->unique(['pay_run_id', 'employee_id'], 'hr_pay_run_lines_run_emp_uq');
                $table->index(['business_id', 'pay_run_id'], 'hr_pay_run_lines_biz_run_idx');
            });
        }

        if (! Schema::hasTable('hr_payslips')) {
            Schema::create('hr_payslips', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->foreignId('pay_run_line_id')->constrained('hr_pay_run_lines')->cascadeOnDelete();
                $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->json('payload_json');
                $table->timestamp('issued_at')->nullable();
                $table->timestamps();
                $table->unique(['pay_run_line_id'], 'hr_payslips_line_uq');
                $table->index(['business_id', 'employee_id'], 'hr_payslips_biz_emp_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_payslips');
        Schema::dropIfExists('hr_pay_run_lines');
        Schema::dropIfExists('hr_pay_runs');
        Schema::dropIfExists('hr_statutory_rate_sets');
        Schema::dropIfExists('hr_employee_compensations');
        Schema::dropIfExists('hr_salary_structures');
    }
};
