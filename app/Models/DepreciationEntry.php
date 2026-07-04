<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepreciationEntry extends Model
{
    protected $fillable = [
        'asset_id',
        'period_id',
        'journal_entry_id',
        'amount',
        'accumulated_depreciation',
        'book_value_after',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'accumulated_depreciation' => 'decimal:2',
            'book_value_after' => 'decimal:2',
        ];
    }

    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }

    public function accountingPeriod(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'period_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }
}
