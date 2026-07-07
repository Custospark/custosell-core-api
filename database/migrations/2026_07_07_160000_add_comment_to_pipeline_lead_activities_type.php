<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement(
            "ALTER TABLE pipeline_lead_activities MODIFY COLUMN type "
            ."ENUM('note','comment','call','email','meeting','stage_change','system') NOT NULL DEFAULT 'note'"
        );
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::table('pipeline_lead_activities')->where('type', 'comment')->update(['type' => 'note']);

        DB::statement(
            "ALTER TABLE pipeline_lead_activities MODIFY COLUMN type "
            ."ENUM('note','call','email','meeting','stage_change','system') NOT NULL DEFAULT 'note'"
        );
    }
};
