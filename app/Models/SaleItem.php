<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleItem extends Model
{
    protected $fillable = [
        'sale_id',
        'product_id',
        'product_name',
        'product_price',
        'quantity',
        'unit_price',
        'unit_cost',
        'subtotal',
        'tax_amount',
        'tax_refunded_amount',
        'discount_amount',
        'refunded_quantity',
        'refunded_amount',
    ];

    protected function casts(): array
    {
        return [
            'product_price' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'tax_refunded_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'quantity' => 'integer',
            'refunded_quantity' => 'integer',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'sale_item_id');
    }
}
