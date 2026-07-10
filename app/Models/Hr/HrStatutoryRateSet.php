<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Models\Business;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrStatutoryRateSet extends Model
{
    protected $table = 'hr_statutory_rate_sets';

    protected $fillable = [
        'business_id',
        'country',
        'effective_from',
        'paye_brackets_json',
        'nssf_employee_rate',
        'nssf_employer_rate',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'paye_brackets_json' => 'array',
            'nssf_employee_rate' => 'decimal:4',
            'nssf_employer_rate' => 'decimal:4',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
