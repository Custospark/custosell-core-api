<?php

namespace App\Models;

use App\Enums\Billing\ReferralStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Referral extends Model
{
    protected $fillable = [
        'referral_code_id',
        'subscription_id',
        'referred_business_id',
        'status',
        'discount_applied',
        'reward_amount',
        'reward_paid',
        'converted_at',
    ];

    protected function casts(): array
    {
        return [
            'discount_applied' => 'decimal:14,2',
            'reward_amount' => 'decimal:14,2',
            'reward_paid' => 'boolean',
            'converted_at' => 'datetime',
            'status' => ReferralStatus::class,
        ];
    }

    public function referralCode(): BelongsTo
    {
        return $this->belongsTo(ReferralCode::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function referredBusiness(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'referred_business_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', ReferralStatus::PENDING);
    }

    public function scopeActive($query)
    {
        return $query->where('status', ReferralStatus::ACTIVE);
    }

    public function scopeRewarded($query)
    {
        return $query->where('status', ReferralStatus::REWARDED);
    }

    public function scopeUnpaid($query)
    {
        return $query->where('status', ReferralStatus::REWARDED)
                     ->where('reward_paid', false);
    }
}
