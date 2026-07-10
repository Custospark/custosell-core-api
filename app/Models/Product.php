<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    public const TYPE_PRODUCT = 'product';

    public const TYPE_SERVICE = 'service';

    protected $fillable = [
        'business_id',
        'category_id',
        'name',
        'type',
        'unit',
        'description',
        'sku',
        'barcode',
        'unit_price',
        'wholesale_price',
        'cost_price',
        'stock_quantity',
        'low_stock_threshold',
        'tax_percentage',
        'tax_class',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'wholesale_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'stock_quantity' => 'integer',
            'low_stock_threshold' => 'integer',
            'tax_percentage' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function tracksStock(): bool
    {
        return ($this->type ?? self::TYPE_PRODUCT) === self::TYPE_PRODUCT;
    }

    public function isService(): bool
    {
        return ($this->type ?? self::TYPE_PRODUCT) === self::TYPE_SERVICE;
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
