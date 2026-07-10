<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Models\Business;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class HrAttendanceDay extends Model
{
    use SoftDeletes;

    protected $table = 'hr_attendance_days';

    protected $fillable = [
        'business_id',
        'employee_id',
        'work_date',
        'status',
        'minutes_worked',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'minutes_worked' => 'integer',
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
