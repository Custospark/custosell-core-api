<?php

declare(strict_types=1);

namespace App\Models\Forecasting;

use App\Models\Business;
use App\Models\ExpenseCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ForecastBudgetLine extends Model
{
    protected $table = 'forecast_budget_lines';

    protected $fillable = [
        'forecast_budget_id',
        'business_id',
        'expense_category_id',
        'label',
        'amount',
        'justification',
        'zbb_status',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(ForecastBudget::class, 'forecast_budget_id');
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function expenseCategory(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class);
    }
}
