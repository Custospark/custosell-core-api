<?php

declare(strict_types=1);

namespace App\Models\Hr;

use App\Models\Business;
use App\Models\JournalEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class HrPayRun extends Model
{
    use SoftDeletes;

    protected $table = 'hr_pay_runs';

    protected $fillable = [
        'business_id',
        'period_start',
        'period_end',
        'status',
        'posted_journal_entry_id',
        'settlement_journal_entry_id',
        'statutory_journal_entry_id',
        'posted_at',
        'net_settled_at',
        'statutory_remitted_at',
        'voided_at',
        'posting_note',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'posted_at' => 'datetime',
            'net_settled_at' => 'datetime',
            'statutory_remitted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(HrPayRunLine::class, 'pay_run_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'posted_journal_entry_id');
    }

    public function settlementJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'settlement_journal_entry_id');
    }

    public function statutoryJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'statutory_journal_entry_id');
    }
}
