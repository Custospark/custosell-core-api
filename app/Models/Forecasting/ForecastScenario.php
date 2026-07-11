<?php

declare(strict_types=1);

namespace App\Models\Forecasting;

use App\Models\Business;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ForecastScenario extends Model
{
    protected $table = 'forecast_scenarios';

    protected $fillable = [
        'business_id',
        'name',
        'horizon_months',
        'hire_basic_salary',
        'extra_monthly_opex',
        'revenue_uplift_pct',
        'payload_json',
    ];

    protected function casts(): array
    {
        return [
            'horizon_months' => 'integer',
            'hire_basic_salary' => 'decimal:2',
            'extra_monthly_opex' => 'decimal:2',
            'revenue_uplift_pct' => 'decimal:2',
            'payload_json' => 'array',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
