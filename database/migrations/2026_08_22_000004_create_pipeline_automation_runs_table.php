<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->constrained('pipeline_automation_rules')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('pipeline_leads')->nullOnDelete();
            $table->string('trigger');
            $table->string('status')->default('success');
            $table->unsignedSmallInteger('actions_executed')->default(0);
            $table->text('message')->nullable();
            $table->json('detail')->nullable();
            $table->timestamps();

            $table->index(['rule_id', 'created_at']);
            $table->index(['business_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_automation_runs');
    }
};