<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'purchase_order_id',
        'product_id',
        'received_product_id',
        'product_name',
        'product_sku',
        'unit_price',
        'quantity',
        'quantity_fulfilled',
        'subtotal',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'quantity' => 'integer',
            'quantity_fulfilled' => 'integer',
            'subtotal' => 'decimal:2',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** The seller's product being ordered. */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** The buyer's local product this line was received into. */
    public function receivedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'received_product_id');
    }
}
