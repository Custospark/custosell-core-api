<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Models\Business;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class HrPayRunLine extends Model
{
    protected $table = 'hr_pay_run_lines';

    protected $fillable = [
        'business_id',
        'pay_run_id',
        'employee_id',
        'gross',
        'paye',
        'nssf_employee',
        'nssf_employer',
        'other_deductions',
        'net',
        'breakdown_json',
    ];

    protected function casts(): array
    {
        return [
            'gross' => 'decimal:2',
            'paye' => 'decimal:2',
            'nssf_employee' => 'decimal:2',
            'nssf_employer' => 'decimal:2',
            'other_deductions' => 'decimal:2',
            'net' => 'decimal:2',
            'breakdown_json' => 'array',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function payRun(): BelongsTo
    {
        return $this->belongsTo(HrPayRun::class, 'pay_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }

    public function payslip(): HasOne
    {
        return $this->hasOne(HrPayslip::class, 'pay_run_line_id');
    }
}
