<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('document_activity_logs')) {
            return;
        }

        if (! Schema::hasColumn('document_activity_logs', 'cabinet_id')) {
            Schema::table('document_activity_logs', function (Blueprint $table) {
                $table->foreignId('cabinet_id')->nullable()->after('business_id')->constrained('document_cabinets')->nullOnDelete();
            });
        }

        if (Schema::hasTable('document_folders') && Schema::hasColumn('document_folders', 'cabinet_id')) {
            DB::table('document_activity_logs')
                ->whereNull('cabinet_id')
                ->whereNotNull('folder_id')
                ->update([
                    'cabinet_id' => DB::raw('(SELECT cabinet_id FROM document_folders WHERE document_folders.id = document_activity_logs.folder_id LIMIT 1)'),
                ]);
        }

        if (Schema::hasTable('documents') && Schema::hasColumn('documents', 'cabinet_id')) {
            DB::table('document_activity_logs')
                ->whereNull('cabinet_id')
                ->where('subject_type', 'document')
                ->whereNotNull('subject_id')
                ->update([
                    'cabinet_id' => DB::raw('(SELECT cabinet_id FROM documents WHERE documents.id = document_activity_logs.subject_id LIMIT 1)'),
                ]);
        }

        DB::table('document_activity_logs')
            ->whereNull('cabinet_id')
            ->where('subject_type', 'cabinet')
            ->whereNotNull('subject_id')
            ->update(['cabinet_id' => DB::raw('subject_id')]);

        if (! $this->indexExists('document_activity_logs', 'document_activity_logs_business_cabinet_created_idx')) {
            Schema::table('document_activity_logs', function (Blueprint $table) {
                $table->index(['business_id', 'cabinet_id', 'created_at'], 'document_activity_logs_business_cabinet_created_idx');
            });
        }
    }

    protected function indexExists(string $table, string $index): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = $connection->select("PRAGMA index_list('{$table}')");

            return collect($indexes)->contains(fn ($row) => ($row->name ?? null) === $index);
        }

        $database = $connection->getDatabaseName();
        $result = $connection->select(
            'SELECT COUNT(*) AS aggregate FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$database, $table, $index],
        );

        return (int) ($result[0]->aggregate ?? 0) > 0;
    }

    public function down(): void
    {
        if (! Schema::hasTable('document_activity_logs') || ! Schema::hasColumn('document_activity_logs', 'cabinet_id')) {
            return;
        }

        if ($this->indexExists('document_activity_logs', 'document_activity_logs_business_cabinet_created_idx')) {
            Schema::table('document_activity_logs', function (Blueprint $table) {
                $table->dropIndex('document_activity_logs_business_cabinet_created_idx');
            });
        }

        Schema::table('document_activity_logs', function (Blueprint $table) {
            $table->dropForeign(['cabinet_id']);
            $table->dropColumn('cabinet_id');
        });
    }
};
