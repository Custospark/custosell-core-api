<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<array{name: string, description: string, cover_color: string, sort_order: int}> */
    protected array $starterCabinets = [
        ['name' => 'General', 'description' => 'Shared company files and everyday documents', 'cover_color' => '#6366f1', 'sort_order' => 0],
        ['name' => 'HR', 'description' => 'People policies, contracts, and HR records', 'cover_color' => '#8b5cf6', 'sort_order' => 1],
        ['name' => 'Finance', 'description' => 'Invoices, budgets, tax records, and accounting files', 'cover_color' => '#059669', 'sort_order' => 2],
        ['name' => 'Legal & Compliance', 'description' => 'Agreements, licenses, and regulatory documents', 'cover_color' => '#dc2626', 'sort_order' => 3],
        ['name' => 'Sales & Marketing', 'description' => 'Proposals, campaigns, brand assets, and collateral', 'cover_color' => '#ea580c', 'sort_order' => 4],
        ['name' => 'Operations', 'description' => 'SOPs, vendor files, and day-to-day operations', 'cover_color' => '#0284c7', 'sort_order' => 5],
    ];

    public function up(): void
    {
        if (Schema::hasTable('document_cabinets') && ! Schema::hasColumn('document_cabinets', 'background_type')) {
            Schema::table('document_cabinets', function (Blueprint $table) {
                $table->string('background_type', 32)->nullable()->after('cover_color');
            });
        }

        if (Schema::hasTable('document_cabinets') && ! Schema::hasColumn('document_cabinets', 'background_value')) {
            Schema::table('document_cabinets', function (Blueprint $table) {
                $table->string('background_value', 500)->nullable()->after('background_type');
            });
        }

        $this->seedStarterCabinets();
    }

    protected function seedStarterCabinets(): void
    {
        if (! Schema::hasTable('businesses') || ! Schema::hasTable('document_cabinets')) {
            return;
        }

        $businessIds = DB::table('businesses')->pluck('id');

        foreach ($businessIds as $businessId) {
            $ownerId = DB::table('businesses')->where('id', $businessId)->value('owner_id');
            if ($ownerId !== null && ! DB::table('users')->where('id', $ownerId)->exists()) {
                $ownerId = null;
            }

            foreach ($this->starterCabinets as $starter) {
                $exists = DB::table('document_cabinets')
                    ->where('business_id', $businessId)
                    ->where('name', $starter['name'])
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('document_cabinets')->insert([
                    'business_id' => $businessId,
                    'name' => $starter['name'],
                    'description' => $starter['description'],
                    'visibility' => 'all_staff',
                    'cover_color' => $starter['cover_color'],
                    'sort_order' => $starter['sort_order'],
                    'created_by' => $ownerId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('document_cabinets')) {
            return;
        }

        if (Schema::hasColumn('document_cabinets', 'background_type') || Schema::hasColumn('document_cabinets', 'background_value')) {
            Schema::table('document_cabinets', function (Blueprint $table) {
                $columns = array_filter([
                    Schema::hasColumn('document_cabinets', 'background_type') ? 'background_type' : null,
                    Schema::hasColumn('document_cabinets', 'background_value') ? 'background_value' : null,
                ]);
                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};
