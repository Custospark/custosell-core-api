<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
        'slug',
        'description',
        'price_monthly',
        'price_yearly',
        'price_monthly_usd',
        'price_yearly_usd',
        'onboarding_fee_ugx',
        'onboarding_fee_usd',
        'trial_days',
        'billing_cycle',
        'features',
        'limits',
        'is_active',
        'is_popular',
        'sort_order',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'price_monthly' => 'decimal:2',
            'price_yearly' => 'decimal:2',
            'price_monthly_usd' => 'float',
            'price_yearly_usd' => 'float',
            'onboarding_fee_ugx' => 'float',
            'onboarding_fee_usd' => 'float',
            'trial_days' => 'integer',
            'features' => 'array',
            'limits' => 'array',
            'is_active' => 'boolean',
            'is_popular' => 'boolean',
            'sort_order' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function subscriptions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('price_monthly');
    }

    public function priceIn(string $currency = 'UGX'): float
    {
        return strtoupper($currency) === 'USD' ? $this->price_monthly_usd : (float) $this->price_monthly;
    }

    public function onboardingFeeIn(string $currency = 'UGX'): float
    {
        return strtoupper($currency) === 'USD' ? $this->onboarding_fee_usd : $this->onboarding_fee_ugx;
    }

    public function hasOnboardingFee(): bool
    {
        return $this->onboarding_fee_ugx > 0 || $this->onboarding_fee_usd > 0;
    }
}
