<?php

namespace App\Models;

use App\Enums\Billing\DiscountType;
use App\Enums\Billing\ReferralCodeOwnerType;
use App\Enums\Billing\RewardType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReferralCode extends Model
{
    protected $fillable = [
        'owner_type',
        'owner_business_id',
        'owner_user_id',
        'code',
        'discount_type',
        'discount_value',
        'discount_duration_months',
        'reward_type',
        'reward_value',
        'max_uses',
        'used_count',
        'is_active',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:14,2',
            'reward_value' => 'decimal:14,2',
            'discount_duration_months' => 'integer',
            'max_uses' => 'integer',
            'used_count' => 'integer',
            'is_active' => 'boolean',
            'expires_at' => 'datetime',
            'discount_type' => DiscountType::class,
            'reward_type' => RewardType::class,
            'owner_type' => ReferralCodeOwnerType::class,
        ];
    }

    public function ownerBusiness(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'owner_business_id');
    }

    public function ownerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(Referral::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
                     ->where(function ($q) {
                         $q->whereNull('expires_at')
                           ->orWhere('expires_at', '>', now());
                     });
    }

    public function scopeValid($query)
    {
        return $query->where('is_active', true)
                     ->where(function ($q) {
                         $q->whereNull('expires_at')
                           ->orWhere('expires_at', '>', now());
                     })
                     ->where(function ($q) {
                         $q->whereNull('max_uses')
                           ->orWhereColumn('used_count', '<', 'max_uses');
                     });
    }

    public function isValid(): bool
    {
        return $this->is_active
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && ($this->max_uses === null || $this->used_count < $this->max_uses);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function markUsed(): void
    {
        $this->increment('used_count');
    }
}
