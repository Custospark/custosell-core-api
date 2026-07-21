<?php

namespace App\Models;

use App\Enums\Billing\CommissionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesRep extends Model
{
    protected $fillable = [
        'user_id',
        'referral_code_id',
        'commission_rate',
        'commission_type',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'commission_rate' => 'decimal:5,2',
            'is_active' => 'boolean',
            'commission_type' => CommissionType::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function referralCode(): BelongsTo
    {
        return $this->belongsTo(ReferralCode::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
