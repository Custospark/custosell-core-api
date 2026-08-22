<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single execution of an automation rule - recorded by the cron scheduler
 * and the event dispatcher so the UI can show a run history per rule.
 */
class PipelineAutomationRun extends Model
{
    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'rule_id',
        'business_id',
        'lead_id',
        'trigger',
        'status',
        'actions_executed',
        'message',
        'detail',
    ];

    protected function casts(): array
    {
        return [
            'actions_executed' => 'integer',
            'detail' => 'array',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(PipelineAutomationRule::class, 'rule_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(PipelineLead::class, 'lead_id');
    }
}