<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('pipeline_lead_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->unsignedBigInteger('parent_estimate_id')->nullable();
            $table->string('estimate_number', 40);
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('title');
            $table->string('status', 20)->default('draft');
            $table->string('currency', 10)->default('UGX');
            $table->decimal('subtotal', 16, 2)->default(0);
            $table->string('discount_type', 10)->nullable();
            $table->decimal('discount_value', 16, 2)->default(0);
            $table->decimal('discount_amount', 16, 2)->default(0);
            $table->decimal('tax_rate', 8, 4)->default(0);
            $table->decimal('tax_total', 16, 2)->default(0);
            $table->decimal('total', 16, 2)->default(0);
            $table->decimal('cost_subtotal', 16, 2)->default(0);
            $table->decimal('gross_profit', 16, 2)->default(0);
            $table->decimal('margin_percent', 8, 2)->default(0);
            $table->date('valid_until')->nullable();
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->text('internal_notes')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('approved_by_name')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->unsignedInteger('email_sent_count')->default(0);
            $table->timestamp('last_emailed_at')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            $table->foreign('pipeline_lead_id')->references('id')->on('pipeline_leads')->nullOnDelete();
            $table->foreign('parent_estimate_id')->references('id')->on('estimates')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users');
            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();
            $table->unique(['business_id', 'estimate_number']);
            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'customer_id']);
        });

        Schema::create('estimate_line_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('estimate_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('type', 20)->default('other');
            $table->text('description');
            $table->decimal('quantity', 12, 4)->default(1);
            $table->decimal('unit_cost', 16, 2)->default(0);
            $table->decimal('unit_price', 16, 2)->default(0);
            $table->string('markup_type', 10)->default('none');
            $table->decimal('markup_value', 16, 2)->default(0);
            $table->decimal('total_cost', 16, 2)->default(0);
            $table->decimal('total_price', 16, 2)->default(0);
            $table->boolean('is_billable')->default(true);
            $table->timestamps();

            $table->foreign('estimate_id')->references('id')->on('estimates')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
        });

        Schema::create('estimate_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('estimate_id');
            $table->unsignedSmallInteger('version');
            $table->json('snapshot');
            $table->unsignedBigInteger('created_by');
            $table->string('change_summary')->nullable();
            $table->timestamp('created_at');

            $table->foreign('estimate_id')->references('id')->on('estimates')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users');
            $table->unique(['estimate_id', 'version']);
        });

        Schema::create('estimate_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('line_items_template');
            $table->text('terms')->nullable();
            $table->decimal('default_tax_rate', 8, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users');
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('estimate_id')->nullable();
            $table->unsignedBigInteger('pipeline_lead_id')->nullable();
            $table->string('project_number', 40);
            $table->string('name');
            $table->string('status', 20)->default('planning');
            $table->string('currency', 10)->default('UGX');
            $table->decimal('budget_revenue', 16, 2)->default(0);
            $table->decimal('budget_cost', 16, 2)->default(0);
            $table->decimal('actual_cost', 16, 2)->default(0);
            $table->decimal('actual_revenue', 16, 2)->default(0);
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->date('completed_at')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('manager_id')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            $table->foreign('estimate_id')->references('id')->on('estimates')->nullOnDelete();
            $table->foreign('pipeline_lead_id')->references('id')->on('pipeline_leads')->nullOnDelete();
            $table->foreign('manager_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users');
            $table->unique(['business_id', 'project_number']);
            $table->index(['business_id', 'status']);
        });

        Schema::create('project_tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('todo');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->decimal('estimated_hours', 10, 2)->default(0);
            $table->decimal('actual_hours', 10, 2)->default(0);
            $table->decimal('budget_cost', 16, 2)->default(0);
            $table->date('due_date')->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->timestamps();

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('timesheet_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('project_task_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->date('entry_date');
            $table->decimal('hours', 8, 2);
            $table->decimal('hourly_rate', 16, 2)->default(0);
            $table->decimal('total_cost', 16, 2)->default(0);
            $table->text('notes')->nullable();
            $table->boolean('is_billable')->default(true);
            $table->string('status', 20)->default('approved');
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('project_task_id')->references('id')->on('project_tasks')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('created_by')->references('id')->on('users');
            $table->index(['project_id', 'entry_date']);
        });

        Schema::create('project_cost_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id');
            $table->unsignedBigInteger('project_id');
            $table->string('allocation_type', 20);
            $table->string('description');
            $table->decimal('amount', 16, 2);
            $table->string('basis', 20)->default('fixed');
            $table->decimal('basis_value', 16, 4)->default(0);
            $table->date('allocation_date');
            $table->unsignedBigInteger('expense_id')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('expense_id')->references('id')->on('expenses')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users');
        });

        Schema::table('estimates', function (Blueprint $table) {
            $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('estimate_id')->nullable()->after('sale_id');
            $table->foreign('estimate_id')->references('id')->on('estimates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['estimate_id']);
            $table->dropColumn('estimate_id');
        });

        Schema::table('estimates', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
        });

        Schema::dropIfExists('project_cost_allocations');
        Schema::dropIfExists('timesheet_entries');
        Schema::dropIfExists('project_tasks');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('estimate_templates');
        Schema::dropIfExists('estimate_versions');
        Schema::dropIfExists('estimate_line_items');
        Schema::dropIfExists('estimates');
    }
};
