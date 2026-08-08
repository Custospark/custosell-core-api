<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SQLite cannot ALTER an enum-backed CHECK column. The three-role migration
 * rewrote row values but never lifted the original `viewer|editor` constraint,
 * so `contributor`/`manager` inserts fail on any SQLite database (including the
 * in-memory test DB). Rebuild the table with the canonical three-role contract.
 * MySQL already has the correct ENUM and is untouched.
 */
return new class extends Migration
{
    private string $table = 'pipeline_board_members';

    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            return;
        }

        $tmp = $this->table.'_role_fix';

        Schema::create($tmp, function (Blueprint $fresh) {
            $fresh->id();
            $fresh->unsignedBigInteger('board_id');
            $fresh->unsignedBigInteger('user_id');
            $fresh->string('role', 16)->default('contributor');
            $fresh->timestamps();

            $fresh->foreign('board_id')->references('id')->on('pipeline_boards')->cascadeOnDelete();
            $fresh->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $fresh->unique(['board_id', 'user_id']);
        });

        DB::table($tmp)->insertUsing(
            ['id', 'board_id', 'user_id', 'role', 'created_at', 'updated_at'],
            DB::table($this->table)
                ->select(
                    'id',
                    'board_id',
                    'user_id',
                    DB::raw("CASE WHEN role = 'editor' THEN 'contributor' ELSE role END AS role"),
                    'created_at',
                    'updated_at',
                ),
        );

        Schema::drop($this->table);
        Schema::rename($tmp, $this->table);
    }

    public function down(): void
    {
        // Not reversible on SQLite; the canonical schema is maintained by the
        // MySQL enum migrations and app-level role normalization.
    }
};
