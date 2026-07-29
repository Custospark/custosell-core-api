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
        'price_monthly_usd',
        'price_yearly_usd',
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
            'price_monthly_usd' => 'float',
            'price_yearly_usd' => 'float',
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
        return $query->orderBy('sort_order')->orderBy('price_monthly_usd');
    }

    public function priceMonthly(): float
    {
        return (float) $this->price_monthly_usd;
    }

    public function priceYearly(): float
    {
        return (float) ($this->price_yearly_usd ?? 0);
    }

    public function hasOnboardingFee(): bool
    {
        return (float) ($this->onboarding_fee_usd ?? 0) > 0;
    }
}
