<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Expand enum first — 'contributor' is invalid while column is still viewer|editor.
        DB::statement(
            "ALTER TABLE pipeline_board_members MODIFY COLUMN role ENUM('viewer', 'editor', 'contributor', 'manager') NOT NULL DEFAULT 'editor'"
        );

        DB::table('pipeline_board_members')
            ->where('role', 'editor')
            ->update(['role' => 'contributor']);

        DB::statement(
            "ALTER TABLE pipeline_board_members MODIFY COLUMN role ENUM('viewer', 'contributor', 'manager') NOT NULL DEFAULT 'contributor'"
        );
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE pipeline_board_members MODIFY COLUMN role ENUM('viewer', 'editor', 'contributor', 'manager') NOT NULL DEFAULT 'contributor'"
        );

        DB::table('pipeline_board_members')
            ->whereIn('role', ['contributor', 'manager'])
            ->update(['role' => 'editor']);

        DB::statement(
            "ALTER TABLE pipeline_board_members MODIFY COLUMN role ENUM('viewer', 'editor') NOT NULL DEFAULT 'editor'"
        );
    }
};
