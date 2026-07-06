<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateTemplate extends Model
{
    protected $fillable = [
        'business_id',
        'name',
        'description',
        'line_items_template',
        'terms',
        'default_tax_rate',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'line_items_template' => 'array',
            'default_tax_rate' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
