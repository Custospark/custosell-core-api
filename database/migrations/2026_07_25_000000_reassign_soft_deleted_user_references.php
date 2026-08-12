<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pre-cleanup for 2026_07_25_000001_hard_delete_soft_deleted_businesses_and_users.
 *
 * That migration hard-deletes soft-deleted users, but several tables hold RESTRICTIVE
 * foreign keys to `users` (journal_entries, invoices, estimates, estimate_versions,
 * estimate_templates, projects, timesheet_entries, project_cost_allocations,
 * board_wall_posts). A restrictive FK blocks the DELETE. This backdated migration runs
 * BEFORE the hard-delete and reassigns those created_by / user_id references to the
 * owning business's owner (skipping owners that are themselves soft-deleted), so the
 * purge can proceed. Idempotent: safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            // [table, business_id source, column]
            ['journal_entries', 'business_id', 'created_by'],
            ['invoices', 'business_id', 'created_by'],
            ['estimates', 'business_id', 'created_by'],
            ['estimate_templates', 'business_id', 'created_by'],
            ['projects', 'business_id', 'created_by'],
            ['timesheet_entries', 'business_id', 'user_id'],
            ['timesheet_entries', 'business_id', 'created_by'],
            ['project_cost_allocations', 'business_id', 'created_by'],
            ['board_wall_posts', 'business_id', 'created_by'],
        ];

        foreach ($tables as [$table, $businessColumn, $userColumn]) {
            $this->reassignFromBusiness($table, $businessColumn, $userColumn);
        }

        // estimate_versions has no direct business_id; join via estimates.
        $this->reassignEstimateVersions();
    }

    private function reassignFromBusiness(string $table, string $businessColumn, string $userColumn): void
    {
        try {
            $table = DB::getTablePrefix().$table;
            DB::statement(
                "UPDATE {$table} AS t
                 JOIN users AS creator ON creator.id = t.{$userColumn} AND creator.deleted_at IS NOT NULL
                 JOIN businesses AS b ON b.id = t.{$businessColumn}
                 JOIN users AS owner ON owner.id = b.owner_id AND owner.deleted_at IS NULL
                 SET t.{$userColumn} = b.owner_id"
            );
        } catch (Throwable $e) {
            // Column or table may not exist in a given environment; never block migration.
            report($e);
        }
    }

    private function reassignEstimateVersions(): void
    {
        try {
            $table = DB::getTablePrefix().'estimate_versions';
            DB::statement(
                "UPDATE {$table} AS ev
                 JOIN estimates AS e ON e.id = ev.estimate_id
                 JOIN users AS creator ON creator.id = ev.created_by AND creator.deleted_at IS NOT NULL
                 JOIN businesses AS b ON b.id = e.business_id
                 JOIN users AS owner ON owner.id = b.owner_id AND owner.deleted_at IS NULL
                 SET ev.created_by = b.owner_id"
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function down(): void
    {
        // No rollback — reassignment is data normalization; original values are gone.
    }
};
