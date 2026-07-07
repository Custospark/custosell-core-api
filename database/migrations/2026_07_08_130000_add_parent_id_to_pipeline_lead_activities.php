<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipeline_lead_activities', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_id')->nullable()->after('lead_id');
            $table->foreign('parent_id')
                ->references('id')
                ->on('pipeline_lead_activities')
                ->cascadeOnDelete();
            $table->index(['lead_id', 'parent_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('pipeline_lead_activities', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropIndex(['lead_id', 'parent_id', 'created_at']);
            $table->dropColumn('parent_id');
        });
    }
};
