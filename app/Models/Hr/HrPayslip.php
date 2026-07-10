<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Models\Business;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrPayslip extends Model
{
    protected $table = 'hr_payslips';

    protected $fillable = [
        'business_id',
        'pay_run_line_id',
        'employee_id',
        'payload_json',
        'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'issued_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function payRunLine(): BelongsTo
    {
        return $this->belongsTo(HrPayRunLine::class, 'pay_run_line_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
}
