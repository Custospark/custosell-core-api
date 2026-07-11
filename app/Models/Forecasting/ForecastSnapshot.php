<?php

declare(strict_types=1);

namespace App\Models\Forecasting;

use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ForecastSnapshot extends Model
{
    protected $table = 'forecast_snapshots';

    protected $fillable = [
        'business_id',
        'forecast_budget_id',
        'label',
        'as_of_date',
        'payload_json',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'as_of_date' => 'date',
            'payload_json' => 'array',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(ForecastBudget::class, 'forecast_budget_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
