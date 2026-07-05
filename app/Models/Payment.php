<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Payment extends Model
{
    protected $fillable = [
        'business_id',
        'payable_type',
        'payable_id',
        'receipt_number',
        'amount',
        'amount_tendered',
        'change_given',
        'payment_method',
        'balance_after',
        'recorded_by',
        'shift_id',
        'paid_at',
        'notes',
        'attachment_path',
        'email_sent_count',
        'last_emailed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'amount_tendered' => 'decimal:2',
            'change_given' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'paid_at' => 'datetime',
            'email_sent_count' => 'integer',
            'last_emailed_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
