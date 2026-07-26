<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditApplication extends Model
{
    protected $fillable = [
        'credit_id', 'subscription_id', 'billing_payment_id',
        'amount_applied', 'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_applied' => 'decimal:2',
            'applied_at' => 'datetime',
        ];
    }

    public function credit(): BelongsTo
    {
        return $this->belongsTo(BillingCredit::class, 'credit_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    public function billingPayment(): BelongsTo
    {
        return $this->belongsTo(BillingPayment::class, 'billing_payment_id');
    }
}
