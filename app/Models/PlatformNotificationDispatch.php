<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformNotificationDispatch extends Model
{
    protected $fillable = [
        'actor_id',
        'dispatch_type',
        'target_kind',
        'intention',
        'subject',
        'message',
        'channel',
        'status_from',
        'status_to',
        'mark_as_notified',
        'recipient_count',
        'recipients',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'mark_as_notified' => 'boolean',
            'recipient_count' => 'integer',
            'recipients' => 'array',
            'metadata' => 'array',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
