<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Models\Business;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class HrEmployeeCompensation extends Model
{
    use SoftDeletes;

    protected $table = 'hr_employee_compensations';

    protected $fillable = [
        'business_id',
        'employee_id',
        'structure_id',
        'basic_salary',
        'allowances_json',
        'deductions_json',
        'effective_from',
    ];

    protected function casts(): array
    {
        return [
            'basic_salary' => 'decimal:2',
            'allowances_json' => 'array',
            'deductions_json' => 'array',
            'effective_from' => 'date',
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

    public function structure(): BelongsTo
    {
        return $this->belongsTo(HrSalaryStructure::class, 'structure_id');
    }
}
