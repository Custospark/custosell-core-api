<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('slug', 255)
                ->nullable()
                ->after('sku');
            $table->unique(['business_id', 'slug']);
        });

        // Backfill slugs for existing products so public product links work
        // without requiring the owner to re-save each row.
        \Illuminate\Support\Facades\DB::table('products')
            ->whereNull('slug')
            ->orderBy('id')
            ->chunkById(200, function ($products) {
                foreach ($products as $product) {
                    $base = \Illuminate\Support\Str::slug((string) $product->name);
                    $base = $base !== '' ? $base : 'product-'.$product->id;
                    $slug = $base;
                    $n = 2;
                    while (\Illuminate\Support\Facades\DB::table('products')
                        ->where('business_id', $product->business_id)
                        ->where('slug', $slug)
                        ->where('id', '!=', $product->id)
                        ->exists()) {
                        $slug = $base.'-'.$n;
                        $n++;
                    }
                    \Illuminate\Support\Facades\DB::table('products')
                        ->where('id', $product->id)
                        ->update(['slug' => $slug]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'slug']);
            $table->dropColumn('slug');
        });
    }
};