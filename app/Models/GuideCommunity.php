<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A company-wide Custosell community (WhatsApp, Telegram, Discord, etc.)
 * that any authenticated Custosell user can join. Managed by platform admins
 * under Guide settings; read by authenticated users via the Communities
 * component.
 */
class GuideCommunity extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'name',
        'description',
        'platform',
        'url',
        'icon',
        'sort_order',
        'is_published',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (GuideCommunity $community): void {
            if (empty($community->uuid)) {
                $community->uuid = (string) Str::uuid();
            }
        });
    }

    /** @param Builder<GuideCommunity> $query */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}