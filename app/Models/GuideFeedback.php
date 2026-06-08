<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class GuideFeedback extends Model
{
    protected $table = 'guide_feedback';

    public const CATEGORY_FEEDBACK = 'feedback';

    public const CATEGORY_FEATURE_REQUEST = 'feature_request';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'uuid',
        'user_id',
        'business_id',
        'category',
        'subject',
        'body',
        'status',
        'staff_reply',
        'admin_internal_notes',
    ];

    protected static function booted(): void
    {
        static::creating(function (GuideFeedback $row): void {
            if (empty($row->uuid)) {
                $row->uuid = (string) Str::uuid();
            }
        });
    }

    /** @return list<string> */
    public static function allowedCategories(): array
    {
        return [self::CATEGORY_FEEDBACK, self::CATEGORY_FEATURE_REQUEST];
    }

    /** @return list<string> */
    public static function allowedStatuses(): array
    {
        return [
            self::STATUS_SUBMITTED,
            self::STATUS_ACKNOWLEDGED,
            self::STATUS_IN_PROGRESS,
            self::STATUS_RESOLVED,
            self::STATUS_CLOSED,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
