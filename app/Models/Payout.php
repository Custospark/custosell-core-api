<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payout extends Model
{
    protected $fillable = [
        'payable_type',
        'payable_id',
        'amount',
        'currency',
        'status',
        'payment_method',
        'notes',
        'attachments',
        'scheduled_at',
        'paid_at',
        'paid_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'attachments' => 'array',
            'scheduled_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function paidByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    public function scopeScheduled($query)
    {
        return $query->where('status', 'scheduled');
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', 'scheduled')
            ->where('scheduled_at', '<', now());
    }
}
