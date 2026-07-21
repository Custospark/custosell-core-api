<?php

namespace App\Models;

use App\Enums\Billing\PaymentMethod;
use App\Enums\Billing\PaymentStatus;
use App\Enums\Billing\PaymentType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingPayment extends Model
{
    use HasFactory;

    protected $table = 'billing_payments';

    protected $fillable = [
        'business_id',
        'subscription_id',
        'amount',
        'currency',
        'method',
        'payment_type',
        'status',
        'transaction_reference',
        'gateway_name',
        'gateway_transaction_id',
        'gateway_request',
        'gateway_response',
        'paid_at',
        'approved_at',
        'approved_by_user_id',
        'rejection_reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'method' => PaymentMethod::class,
            'payment_type' => PaymentType::class,
            'status' => PaymentStatus::class,
            'paid_at' => 'datetime',
            'approved_at' => 'datetime',
            'gateway_request' => 'array',
            'gateway_response' => 'array',
            'metadata' => 'array',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', PaymentStatus::PENDING);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', PaymentStatus::COMPLETED);
    }

    public function isPending(): bool
    {
        return $this->status === PaymentStatus::PENDING;
    }

    public function isCompleted(): bool
    {
        return $this->status === PaymentStatus::COMPLETED;
    }
}
