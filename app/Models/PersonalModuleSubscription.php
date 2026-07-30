<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonalModuleSubscription extends Model
{
    protected $fillable = [
        'user_id',
        'module_slug',
        'status',
        'billing_cycle',
        'price_usd',
        'current_period_start',
        'current_period_end',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'price_usd' => 'float',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
