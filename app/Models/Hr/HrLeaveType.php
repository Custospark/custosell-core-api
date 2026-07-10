<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Models\Business;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class HrLeaveType extends Model
{
    use SoftDeletes;

    protected $table = 'hr_leave_types';

    protected $fillable = [
        'business_id',
        'name',
        'code',
        'paid',
        'days_per_year',
        'requires_approval',
    ];

    protected function casts(): array
    {
        return [
            'paid' => 'boolean',
            'days_per_year' => 'decimal:2',
            'requires_approval' => 'boolean',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(HrLeaveBalance::class, 'leave_type_id');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(HrLeaveRequest::class, 'leave_type_id');
    }
}
