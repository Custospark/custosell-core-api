<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Models\Business;
use App\Models\FixedAsset;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class HrEmployee extends Model
{
    use SoftDeletes;

    protected $table = 'hr_employees';

    protected $fillable = [
        'business_id',
        'user_id',
        'employee_number',
        'first_name',
        'last_name',
        'email',
        'phone',
        'department_id',
        'position_id',
        'manager_employee_id',
        'employment_type',
        'status',
        'hire_date',
        'termination_date',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'hire_date' => 'date',
            'termination_date' => 'date',
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

    public function department(): BelongsTo
    {
        return $this->belongsTo(HrDepartment::class, 'department_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(HrPosition::class, 'position_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_employee_id');
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(self::class, 'manager_employee_id');
    }

    public function compensations(): HasMany
    {
        return $this->hasMany(HrEmployeeCompensation::class, 'employee_id');
    }

    public function assignedAssets(): HasMany
    {
        return $this->hasMany(FixedAsset::class, 'assigned_employee_id');
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}
