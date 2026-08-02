<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class StaffTransfer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'business_id',
        'user_id',
        'from_location_id',
        'to_location_id',
        'transferred_by',
        'transfer_type',
        'status',
        'approval_required',
        'approved_by',
        'approved_at',
        'effective_at',
        'end_at',
        'reason',
        'notes',
        'old_role_id',
        'new_role_id',
        'old_shift_id',
        'new_shift_id',
        'old_salary',
        'new_salary',
        'old_employment_type',
        'new_employment_type',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'approval_required' => 'boolean',
            'approved_at' => 'datetime',
            'effective_at' => 'date',
            'end_at' => 'date',
            'old_salary' => 'decimal:2',
            'new_salary' => 'decimal:2',
            'meta' => 'array',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    public function transferredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transferred_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
