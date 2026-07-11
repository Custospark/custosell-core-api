<?php

declare(strict_types=1);

namespace App\Models\Forecasting;

use App\Models\Business;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ForecastBudget extends Model
{
    protected $table = 'forecast_budgets';

    protected $fillable = [
        'business_id',
        'year',
        'name',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ForecastBudgetLine::class, 'forecast_budget_id')->orderBy('sort_order');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(ForecastSnapshot::class, 'forecast_budget_id');
    }
}
