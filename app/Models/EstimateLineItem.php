<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateLineItem extends Model
{
    protected $fillable = [
        'estimate_id',
        'product_id',
        'sort_order',
        'type',
        'description',
        'quantity',
        'unit_cost',
        'unit_price',
        'markup_type',
        'markup_value',
        'total_cost',
        'total_price',
        'is_billable',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'markup_value' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'total_price' => 'decimal:2',
            'is_billable' => 'boolean',
        ];
    }

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
