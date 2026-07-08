<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipeline_board_messages', function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->after('body');
        });

        // Best-effort backfill: messages logged as automation conversation posts.
        if (Schema::hasTable('pipeline_board_activity_events')) {
            $messageIds = DB::table('pipeline_board_activity_events')
                ->where('event_type', 'message')
                ->where('title', 'like', 'Automation%')
                ->where('entity_type', 'message')
                ->whereNotNull('entity_id')
                ->pluck('entity_id');

            if ($messageIds->isNotEmpty()) {
                DB::table('pipeline_board_messages')
                    ->whereIn('id', $messageIds)
                    ->update(['is_system' => true]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('pipeline_board_messages', function (Blueprint $table) {
            $table->dropColumn('is_system');
        });
    }
};
