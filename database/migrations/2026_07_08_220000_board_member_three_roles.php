<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('pipeline_board_members')
            ->where('role', 'editor')
            ->update(['role' => 'contributor']);

        DB::statement(
            "ALTER TABLE pipeline_board_members MODIFY COLUMN role ENUM('viewer', 'contributor', 'manager') NOT NULL DEFAULT 'contributor'"
        );
    }

    public function down(): void
    {
        DB::table('pipeline_board_members')
            ->whereIn('role', ['contributor', 'manager'])
            ->update(['role' => 'editor']);

        DB::statement(
            "ALTER TABLE pipeline_board_members MODIFY COLUMN role ENUM('viewer', 'editor') NOT NULL DEFAULT 'editor'"
        );
    }
};
