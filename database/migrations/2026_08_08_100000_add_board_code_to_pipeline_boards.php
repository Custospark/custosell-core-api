<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Adds an opaque, non-sequential `code` to pipeline_boards so client URLs
     * never expose the raw auto-increment DB id. Existing rows get a random
     * code backfilled so every published board keeps working after deploy.
     */
    public function up(): void
    {
        if (Schema::hasColumn('pipeline_boards', 'code')) {
            return;
        }

        Schema::table('pipeline_boards', function (Blueprint $table) {
            $table->string('code', 32)->nullable()->after('id');
        });

        DB::table('pipeline_boards')->orderBy('id')->chunkById(200, function ($boards) {
            foreach ($boards as $board) {
                DB::table('pipeline_boards')
                    ->where('id', $board->id)
                    ->update(['code' => Str::random(32)]);
            }
        });

        Schema::table('pipeline_boards', function (Blueprint $table) {
            $table->string('code', 32)->nullable(false)->change();
            $table->unique('code');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('pipeline_boards') && Schema::hasColumn('pipeline_boards', 'code')) {
            Schema::table('pipeline_boards', function (Blueprint $table) {
                $table->dropUnique(['code']);
                $table->dropColumn('code');
            });
        }
    }
};