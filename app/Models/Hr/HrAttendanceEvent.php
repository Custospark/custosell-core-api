<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Models\Business;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrAttendanceEvent extends Model
{
    protected $table = 'hr_attendance_events';

    protected $fillable = [
        'business_id',
        'employee_id',
        'type',
        'occurred_at',
        'source',
        'note',
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

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
}
