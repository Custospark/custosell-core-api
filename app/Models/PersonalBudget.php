<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named personal budget (goal pot) with its own planned amount and coverage
 * period. Actuals come from the income/expense records linked via budget_id,
 * keeping income, budgets, and expenses in sync rather than in silos.
 */
class PersonalBudget extends Model
{
    protected $fillable = [
        'business_id',
        'user_id',
        'name',
        'description',
        'planned_amount',
        'period_start',
        'period_end',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'planned_amount' => 'decimal:2',
            'period_start' => 'date',
            'period_end' => 'date',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function linkedExpenses()
    {
        return $this->hasMany(Expense::class, 'budget_id');
    }

    public function linkedIncome()
    {
        return $this->hasMany(IncomeSource::class, 'budget_id');
    }
}