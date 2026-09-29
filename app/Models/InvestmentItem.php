<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvestmentItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'category',
        'name',
        'specs',
        'price_ugx',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price_ugx' => 'decimal:2',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
