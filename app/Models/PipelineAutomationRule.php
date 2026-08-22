<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A board automation rule: "When [trigger], if [conditions], then [actions]".
 * The cron engine scans scheduled rules and fires them idempotently.
 */
class PipelineAutomationRule extends Model
{
    use SoftDeletes;

    public const TRIGGER_STAGE_ENTERED = 'stage_entered';

    public const TRIGGER_STAGE_EXITED = 'stage_exited';

    public const TRIGGER_STATUS_CHANGED = 'status_changed';

    public const TRIGGER_CARD_CREATED = 'card_created';

    public const TRIGGER_ASSIGNED = 'assigned';

    public const TRIGGER_LABEL_ADDED = 'label_added';

    public const TRIGGER_FIELD_CHANGED = 'field_changed';

    public const TRIGGER_CONVERTED = 'converted_to_customer';

    public const TRIGGER_DUE_PASSED = 'due_date_passed';

    public const TRIGGER_OVERDUE_BY = 'overdue_by';

    public const TRIGGER_BEFORE_DUE = 'before_due';

    public const TRIGGER_STAGE_DWELL = 'stage_dwell';

    public const TRIGGER_NO_ACTIVITY = 'no_activity';

    public const TRIGGER_CREATED_X_DAYS = 'created_x_days_ago';

    public const TRIGGER_RECURRING = 'recurring';

    public const FREQUENCY_ONCE = 'once';

    public const FREQUENCY_DAILY = 'daily';

    public const FREQUENCY_WEEKLY = 'weekly';

    public const FREQUENCY_MONTHLY = 'monthly';

    public const FREQUENCY_CRON = 'cron';

    public const ACTION_MOVE_TO_STAGE = 'move_to_stage';

    public const ACTION_CREATE_CARD = 'create_card';

    public const ACTION_ASSIGN_TO = 'assign_to';

    public const ACTION_ADD_LABEL = 'add_label';

    public const ACTION_REMOVE_LABEL = 'remove_label';

    public const ACTION_SET_PRIORITY = 'set_priority';

    public const ACTION_SET_DUE_DATE = 'set_due_date';

    public const ACTION_SET_FIELD = 'set_field';

    public const ACTION_POST_CONVERSATION = 'post_conversation';

    public const ACTION_NOTIFY = 'notify';

    public const ACTION_NOTIFY_EMAIL = 'notify_email';

    public const ACTION_CREATE_CHECKLIST = 'create_checklist';

    public const ACTION_CREATE_TASK = 'create_task';

    public const ACTION_CONVERT_TO_CUSTOMER = 'convert_to_customer';

    public const ACTION_COPY_CARD = 'copy_card';

    public const ACTION_ARCHIVE = 'archive';

    public const ACTION_WEBHOOK = 'webhook';

    protected $fillable = [
        'business_id',
        'board_id',
        'created_by',
        'name',
        'trigger',
        'conditions',
        'actions',
        'is_active',
        'run_count',
        'last_run_at',
        'paused_at',
        'consecutive_failures',
    ];

    protected function casts(): array
    {
        return [
            'trigger' => 'array',
            'conditions' => 'array',
            'actions' => 'array',
            'is_active' => 'boolean',
            'run_count' => 'integer',
            'last_run_at' => 'datetime',
            'paused_at' => 'datetime',
            'consecutive_failures' => 'integer',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(PipelineBoard::class, 'board_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** True when this rule uses a scheduled (cron-scanned) trigger. */
    public function isScheduledTrigger(): bool
    {
        $type = (string) ($this->trigger['type'] ?? '');

        return in_array($type, [
            self::TRIGGER_DUE_PASSED,
            self::TRIGGER_OVERDUE_BY,
            self::TRIGGER_BEFORE_DUE,
            self::TRIGGER_STAGE_DWELL,
            self::TRIGGER_NO_ACTIVITY,
            self::TRIGGER_CREATED_X_DAYS,
            self::TRIGGER_RECURRING,
        ], true);
    }

    /**
     * Whether the rule's frequency currently matches "now". Used by the
     * scheduler so a rule only fires at the right times - e.g. every Monday,
     * weekly at 9am, on the 1st of the month, or on a custom cron expression.
     */
    public function frequencyMatches(?\Carbon\CarbonInterface $asOf = null): bool
    {
        if (! $this->isScheduledTrigger()) {
            return true;
        }

        $now = $asOf ?? now();
        $trigger = $this->trigger;
        $frequency = (string) ($trigger['frequency'] ?? self::FREQUENCY_ONCE);
        $time = (string) ($trigger['time'] ?? '00:00');
        $cron = (string) ($trigger['cron'] ?? '');

        // "once" fires on the next scan and never again (guarded by last_run_at).
        if ($frequency === self::FREQUENCY_ONCE) {
            return $this->last_run_at === null;
        }

        return match ($frequency) {
            self::FREQUENCY_DAILY => $this->timeMatches($now, $time),
            self::FREQUENCY_WEEKLY => $this->weeklyMatches($now, $trigger) && $this->timeMatches($now, $time),
            self::FREQUENCY_MONTHLY => $this->monthlyMatches($now, $trigger) && $this->timeMatches($now, $time),
            self::FREQUENCY_CRON => $cron !== '' && $this->cronMatches($cron, $now),
            default => $this->timeMatches($now, $time),
        };
    }

    protected function timeMatches(\Carbon\CarbonInterface $now, string $time): bool
    {
        [$h, $m] = array_pad(explode(':', $time), 2, '00');

        return (int) $now->format('H') === (int) $h && (int) $now->format('i') === (int) $m;
    }

    protected function weeklyMatches(\Carbon\CarbonInterface $now, array $trigger): bool
    {
        $days = $trigger['days_of_week'] ?? [];
        if (empty($days)) {
            return true;
        }

        $today = (int) $now->dayOfWeek; // 0 = Sunday
        $mapped = array_map('intval', (array) $days);

        return in_array($today, $mapped, true);
    }

    protected function monthlyMatches(\Carbon\CarbonInterface $now, array $trigger): bool
    {
        $day = (int) ($trigger['day_of_month'] ?? $now->day);
        if ($day <= 0) {
            return true;
        }

        return (int) $now->day === $day;
    }

    protected function cronMatches(string $cron, \Carbon\CarbonInterface $now): bool
    {
        if (! class_exists(\Cron\CronExpression::class)) {
            return false;
        }

        try {
            return \Cron\CronExpression::factory($cron)->isDue($now->toDateTimeString(), 'UTC');
        } catch (\Throwable) {
            return false;
        }
    }
}