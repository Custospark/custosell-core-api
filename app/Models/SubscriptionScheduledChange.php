<?php

namespace App\Models;

use App\Enums\Billing\ScheduledChangeStatus;
use App\Enums\Billing\ScheduledChangeType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionScheduledChange extends Model
{
    protected $fillable = [
        'subscription_id',
        'business_id',
        'change_type',
        'from_plan_id',
        'to_plan_id',
        'effective_at',
        'status',
        'proration_amount',
        'requested_by_user_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'change_type' => ScheduledChangeType::class,
            'status' => ScheduledChangeStatus::class,
            'effective_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function fromPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'from_plan_id');
    }

    public function toPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'to_plan_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', ScheduledChangeStatus::PENDING);
    }

    public function scopeDue($query)
    {
        return $query->where('status', ScheduledChangeStatus::PENDING)
            ->where('effective_at', '<=', now());
    }
}
