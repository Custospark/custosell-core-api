<?php

namespace App\Models;

use App\Enums\Billing\SubscriptionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Subscription extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'business_id',
        'plan_id',
        'billing_cycle',
        'status',
        'starts_at',
        'trial_ends_at',
        'ends_at',
        'next_billing_date',
        'grace_period_ends_at',
        'suspended_at',
        'cancelled_at',
        'approved_at',
        'approved_by_user_id',
        'onboarding_fee_paid',
        'notes',
        'metadata',
        'trial_used',
        'grace_used',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'starts_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'ends_at' => 'datetime',
            'next_billing_date' => 'datetime',
            'grace_period_ends_at' => 'datetime',
            'suspended_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'approved_at' => 'datetime',
            'onboarding_fee_paid' => 'boolean',
            'trial_used' => 'boolean',
            'grace_used' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BillingPayment::class, 'subscription_id');
    }

    public function scheduledChanges(): HasMany
    {
        return $this->hasMany(SubscriptionScheduledChange::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', SubscriptionStatus::ACTIVE);
    }

    public function scopeTrialExpired($query)
    {
        return $query->where('status', SubscriptionStatus::TRIAL->value)
                     ->where('trial_ends_at', '<', now());
    }

    public function scopePastDueExpired($query)
    {
        return $query->where('status', SubscriptionStatus::PAST_DUE->value)
                     ->where('grace_period_ends_at', '<', now());
    }

    public function scopeCancelAtPeriodEnd($query)
    {
        return $query->where('status', SubscriptionStatus::ACTIVE->value)
                     ->where('metadata->cancel_at_period_end', true)
                     ->where('next_billing_date', '<=', now());
    }

    public function scopeRenewable($query)
    {
        return $query->where('status', SubscriptionStatus::ACTIVE->value)
                     ->where(function ($q) {
                         $q->whereNull('metadata->cancel_at_period_end')
                           ->orWhere('metadata->cancel_at_period_end', false);
                     })
                     ->where('next_billing_date', '<=', now());
    }

    public function hasAccess(): bool
    {
        return match ($this->status) {
            SubscriptionStatus::ACTIVE => $this->hasActivePeriodAccess(),
            SubscriptionStatus::TRIAL => $this->trial_ends_at?->isFuture() ?? true,
            SubscriptionStatus::PAST_DUE => $this->grace_period_ends_at?->isFuture() ?? false,
            default => false,
        };
    }

    private function hasActivePeriodAccess(): bool
    {
        if (! ($this->metadata['cancel_at_period_end'] ?? false)) {
            return true;
        }

        return $this->ends_at?->isFuture() ?? false;
    }

    public function isActive(): bool
    {
        return $this->status === SubscriptionStatus::ACTIVE;
    }

    public function isOnTrial(): bool
    {
        return $this->status === SubscriptionStatus::TRIAL
            && ($this->trial_ends_at?->isFuture() ?? true);
    }

    public function isInGrace(): bool
    {
        return $this->status === SubscriptionStatus::PAST_DUE
            && ($this->grace_period_ends_at?->isFuture() ?? false);
    }

    public function isPastDue(): bool
    {
        return $this->status === SubscriptionStatus::PAST_DUE;
    }

    public function isSuspended(): bool
    {
        return $this->status === SubscriptionStatus::SUSPENDED;
    }

    public function isCancelled(): bool
    {
        return $this->status === SubscriptionStatus::CANCELLED;
    }

    public function isCancelAtPeriodEnd(): bool
    {
        return (bool) ($this->metadata['cancel_at_period_end'] ?? false)
            && $this->status === SubscriptionStatus::ACTIVE;
    }

    public function currentPeriodEndsAt(): ?Carbon
    {
        return $this->next_billing_date ?? $this->ends_at;
    }

    public function daysRemaining(): int
    {
        if ($this->isOnTrial() && $this->trial_ends_at?->isFuture()) {
            return $this->calendarDaysUntil($this->trial_ends_at);
        }

        $periodEnd = $this->currentPeriodEndsAt();

        return $periodEnd ? $this->calendarDaysUntil($periodEnd) : 0;
    }

    private function calendarDaysUntil(Carbon $target): int
    {
        $today = Carbon::now()->startOfDay();
        $end = $target->copy()->startOfDay();

        if ($end->lte($today)) {
            return 0;
        }

        return (int) $today->diffInDays($end);
    }
}
