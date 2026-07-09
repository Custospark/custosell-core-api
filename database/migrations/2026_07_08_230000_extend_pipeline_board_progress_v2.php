<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('pipeline_board_targets', 'planning_level')) {
            Schema::table('pipeline_board_targets', function (Blueprint $table) {
                $table->enum('planning_level', ['decade', 'five_year', 'year', 'quarter', 'month', 'week', 'day'])
                    ->nullable()
                    ->after('period_type');
                $table->date('anchor_start')->nullable()->after('planning_level');
                $table->date('anchor_end')->nullable()->after('anchor_start');
                $table->unsignedBigInteger('stage_id')->nullable()->after('board_id');
                $table->enum('decomposition_mode', ['equal', 'velocity', 'hybrid'])->default('hybrid')->after('status');
                $table->enum('goal_tag', ['kpi', 'goal', 'objective', 'deliverable', 'decision'])->nullable()->after('type');

                $table->foreign('stage_id')->references('id')->on('pipeline_stages')->nullOnDelete();
                $table->index(['board_id', 'stage_id'], 'pb_targets_board_stage_idx');
            });
        }

        if (! Schema::hasTable('pipeline_board_target_allocations')) {
            Schema::create('pipeline_board_target_allocations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('business_id');
                $table->unsignedBigInteger('target_id');
                $table->unsignedBigInteger('stage_id')->nullable();
                $table->enum('planning_level', ['decade', 'five_year', 'year', 'quarter', 'month', 'week', 'day']);
                $table->date('period_start');
                $table->date('period_end');
                $table->decimal('expected_value', 16, 4);
                $table->decimal('actual_value', 16, 4)->default(0);
                $table->unsignedBigInteger('member_user_id')->nullable();
                $table->unsignedTinyInteger('weight')->default(100);
                $table->boolean('is_override')->default(false);
                $table->timestamps();

                $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
                $table->foreign('target_id')->references('id')->on('pipeline_board_targets')->cascadeOnDelete();
                $table->foreign('stage_id')->references('id')->on('pipeline_stages')->nullOnDelete();
                $table->foreign('member_user_id')->references('id')->on('users')->nullOnDelete();
                $table->index(['target_id', 'planning_level'], 'pb_alloc_target_level_idx');
                $table->index(['target_id', 'period_start', 'period_end'], 'pb_alloc_target_period_idx');
            });
        } else {
            Schema::table('pipeline_board_target_allocations', function (Blueprint $table) {
                if (! $this->indexExists('pipeline_board_target_allocations', 'pb_alloc_target_level_idx')) {
                    $table->index(['target_id', 'planning_level'], 'pb_alloc_target_level_idx');
                }
                if (! $this->indexExists('pipeline_board_target_allocations', 'pb_alloc_target_period_idx')) {
                    $table->index(['target_id', 'period_start', 'period_end'], 'pb_alloc_target_period_idx');
                }
            });
        }

        if (! Schema::hasTable('pipeline_board_progress_configs')) {
            Schema::create('pipeline_board_progress_configs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('business_id');
                $table->unsignedBigInteger('board_id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->json('config_json');
                $table->timestamps();

                $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
                $table->foreign('board_id')->references('id')->on('pipeline_boards')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
                $table->unique(['board_id', 'user_id'], 'pb_progress_cfg_board_user_uniq');
            });
        }

        if (! Schema::hasTable('pipeline_board_target_events')) {
            Schema::create('pipeline_board_target_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('business_id');
                $table->unsignedBigInteger('target_id');
                $table->unsignedBigInteger('user_id');
                $table->string('event_type', 64);
                $table->json('payload')->nullable();
                $table->timestamps();

                $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
                $table->foreign('target_id')->references('id')->on('pipeline_board_targets')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->index(['target_id', 'created_at'], 'pb_target_events_target_created_idx');
            });
        }

        if (! Schema::hasColumn('pipeline_board_metric_snapshots', 'stage_id')) {
            Schema::table('pipeline_board_metric_snapshots', function (Blueprint $table) {
                $table->unsignedBigInteger('stage_id')->nullable()->after('board_id');
                $table->foreign('stage_id')->references('id')->on('pipeline_stages')->nullOnDelete();
            });
        }

        if ($this->indexExists('pipeline_board_metric_snapshots', 'pipeline_board_metric_snapshots_unique')) {
            Schema::table('pipeline_board_metric_snapshots', function (Blueprint $table) {
                $table->dropUnique('pipeline_board_metric_snapshots_unique');
            });
        }

        if (! $this->indexExists('pipeline_board_metric_snapshots', 'pb_metric_snapshots_unique_v2')) {
            Schema::table('pipeline_board_metric_snapshots', function (Blueprint $table) {
                $table->unique(
                    ['board_id', 'snapshot_date', 'metric_key', 'scope', 'member_user_id', 'stage_id'],
                    'pb_metric_snapshots_unique_v2',
                );
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists('pipeline_board_metric_snapshots', 'pb_metric_snapshots_unique_v2')) {
            Schema::table('pipeline_board_metric_snapshots', function (Blueprint $table) {
                $table->dropUnique('pb_metric_snapshots_unique_v2');
            });
        }

        if (! $this->indexExists('pipeline_board_metric_snapshots', 'pipeline_board_metric_snapshots_unique')) {
            Schema::table('pipeline_board_metric_snapshots', function (Blueprint $table) {
                $table->unique(
                    ['board_id', 'snapshot_date', 'metric_key', 'scope', 'member_user_id'],
                    'pipeline_board_metric_snapshots_unique',
                );
            });
        }

        if (Schema::hasColumn('pipeline_board_metric_snapshots', 'stage_id')) {
            Schema::table('pipeline_board_metric_snapshots', function (Blueprint $table) {
                $table->dropForeign(['stage_id']);
                $table->dropColumn('stage_id');
            });
        }

        Schema::dropIfExists('pipeline_board_target_events');
        Schema::dropIfExists('pipeline_board_progress_configs');
        Schema::dropIfExists('pipeline_board_target_allocations');

        if (Schema::hasColumn('pipeline_board_targets', 'planning_level')) {
            Schema::table('pipeline_board_targets', function (Blueprint $table) {
                $table->dropForeign(['stage_id']);
                if ($this->indexExists('pipeline_board_targets', 'pb_targets_board_stage_idx')) {
                    $table->dropIndex('pb_targets_board_stage_idx');
                }
                $table->dropColumn([
                    'planning_level',
                    'anchor_start',
                    'anchor_end',
                    'stage_id',
                    'decomposition_mode',
                    'goal_tag',
                ]);
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();

        $result = $connection->select(
            'SELECT COUNT(*) AS aggregate FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$database, $table, $indexName],
        );

        return (int) ($result[0]->aggregate ?? 0) > 0;
    }
};
