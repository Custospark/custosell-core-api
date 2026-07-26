<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class BillingCredit extends Model
{
    protected $fillable = [
        'owner_type', 'owner_id', 'referral_id',
        'amount', 'amount_used', 'status', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'amount_used' => 'decimal:2',
            'expires_at' => 'datetime',
        ];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function referral(): BelongsTo
    {
        return $this->belongsTo(Referral::class, 'referral_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(CreditApplication::class, 'credit_id');
    }

    public function getAmountRemainingAttribute(): float
    {
        return round((float) $this->amount - (float) $this->amount_used, 2);
    }
}
