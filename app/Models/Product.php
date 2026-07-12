<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'is_recurring',
        'billing_interval',
        'listed_for_supply',
        'supply_price',
        'supply_min_qty',
        'listed_at',
        'listed_for_storefront',
        'image_path',
        'storefront_listed_at',
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
            'is_recurring' => 'boolean',
            'listed_for_supply' => 'boolean',
            'supply_price' => 'decimal:2',
            'supply_min_qty' => 'integer',
            'listed_at' => 'datetime',
            'listed_for_storefront' => 'boolean',
            'storefront_listed_at' => 'datetime',
        ];
    }

    public function tracksStock(): bool
    {
        return ($this->type ?? self::TYPE_PRODUCT) === self::TYPE_PRODUCT;
    }

    /** Visible on the supply marketplace only when opted-in, active, and a stocked product. */
    public function isListedForSupply(): bool
    {
        return (bool) $this->listed_for_supply && (bool) $this->is_active && $this->tracksStock();
    }

    public function supplyUnitPrice(): float
    {
        return (float) ($this->supply_price ?? $this->unit_price);
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

    public function purchaseOrderItems(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function storefrontRatings(): HasMany
    {
        return $this->hasMany(ProductStorefrontRating::class);
    }

    /** Eager-load with a user_id constraint for the signed-in viewer. */
    public function myStorefrontRating(): HasOne
    {
        return $this->hasOne(ProductStorefrontRating::class);
    }
}
