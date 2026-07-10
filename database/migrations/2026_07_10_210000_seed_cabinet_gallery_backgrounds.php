<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<array{name: string, cover_color: string, background_value: string}> */
    protected array $starterBackgrounds = [
        ['name' => 'General', 'cover_color' => '#6366f1', 'background_value' => 'https://picsum.photos/id/10/1200/800'],
        ['name' => 'HR', 'cover_color' => '#8b5cf6', 'background_value' => 'https://picsum.photos/id/15/1200/800'],
        ['name' => 'Finance', 'cover_color' => '#059669', 'background_value' => 'https://picsum.photos/id/26/1200/800'],
        ['name' => 'Legal & Compliance', 'cover_color' => '#dc2626', 'background_value' => 'https://picsum.photos/id/28/1200/800'],
        ['name' => 'Sales & Marketing', 'cover_color' => '#ea580c', 'background_value' => 'https://picsum.photos/id/36/1200/800'],
        ['name' => 'Operations', 'cover_color' => '#0284c7', 'background_value' => 'https://picsum.photos/id/40/1200/800'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('document_cabinets')
            || ! Schema::hasColumn('document_cabinets', 'background_type')
            || ! Schema::hasColumn('document_cabinets', 'background_value')) {
            return;
        }

        foreach ($this->starterBackgrounds as $starter) {
            $rows = DB::table('document_cabinets')
                ->where('name', $starter['name'])
                ->where(function ($query): void {
                    $query->whereNull('background_type')
                        ->orWhere('background_type', '')
                        ->orWhereNull('background_value')
                        ->orWhere('background_value', '');
                })
                ->get(['id', 'cover_color']);

            foreach ($rows as $row) {
                DB::table('document_cabinets')
                    ->where('id', $row->id)
                    ->update([
                        'cover_color' => $row->cover_color ?: $starter['cover_color'],
                        'background_type' => 'gallery',
                        'background_value' => $starter['background_value'],
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    public function down(): void
    {
        // Keep gallery backgrounds — removing them would blank customized canvases.
    }
};
