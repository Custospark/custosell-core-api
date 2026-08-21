<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill pricing_unit for products created before the field existed.
     * Products that already carry a recognised unit (Kg, Litre, Piece, ...)
     * get a machine-readable pricing_unit so the quantity selector knows
     * whether the product accepts fractional quantities at checkout.
     * Products with unrecognised/custom units keep pricing_unit NULL and
     * behave as integer (piece) items - nothing breaks.
     */
    public function up(): void
    {
        $known = [
            'kg' => 'kg', 'g' => 'g', 'tonne' => 'tonne',
            'litre' => 'litre', 'ml' => 'ml',
            'piece' => 'piece', 'box' => 'box', 'dozen' => 'dozen',
            'packet' => 'packet', 'bag' => 'bag', 'bundle' => 'bundle',
            'carton' => 'carton', 'pair' => 'pair',
        ];

        DB::table('products')
            ->whereNull('pricing_unit')
            ->whereNotNull('unit')
            ->orderBy('id')
            ->chunkById(500, function ($products) use ($known) {
                foreach ($products as $product) {
                    $key = strtolower(trim((string) $product->unit));
                    $key = str_replace(' ', '_', $key);
                    if (isset($known[$key])) {
                        DB::table('products')
                            ->where('id', $product->id)
                            ->update(['pricing_unit' => $known[$key]]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Backfill is data normalisation; no rollback.
    }
};