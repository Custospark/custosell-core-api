<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeneralLedger extends Model
{
    protected $table = 'general_ledger';

    protected $fillable = [
        'business_id',
        'account_id',
        'period_id',
        'opening_balance',
        'total_debits',
        'total_credits',
        'closing_balance',
    ];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'total_debits' => 'decimal:2',
            'total_credits' => 'decimal:2',
            'closing_balance' => 'decimal:2',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function chartOfAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    public function accountingPeriod(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'period_id');
    }
}
