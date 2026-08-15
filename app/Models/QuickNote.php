<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class QuickNote extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'business_id',
        'user_id',
        'client_uuid',
        'title',
        'body',
        'color',
        'tag',
        'is_shared',
        'is_pinned',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_shared' => 'boolean',
            'is_pinned' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (QuickNote $note): void {
            if (empty($note->client_uuid)) {
                $note->client_uuid = (string) Str::uuid();
            }
        });
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
