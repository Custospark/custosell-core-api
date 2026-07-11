<?php

namespace App\Models;

use App\Models\Hr\HrEmployee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FixedAssetAssignment extends Model
{
    protected $fillable = [
        'business_id',
        'asset_id',
        'from_employee_id',
        'to_employee_id',
        'action',
        'notes',
        'performed_by',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }

    public function fromEmployee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'from_employee_id');
    }

    public function toEmployee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'to_employee_id');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
