<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GuideTutorial extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'title',
        'description',
        'video_url',
        'thumbnail_url',
        'thumbnail_path',
        'banner_image_url',
        'category',
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
        static::creating(function (GuideTutorial $tutorial): void {
            if (empty($tutorial->uuid)) {
                $tutorial->uuid = (string) Str::uuid();
            }
        });

        static::forceDeleted(function (GuideTutorial $tutorial): void {
            if ($tutorial->thumbnail_path) {
                Storage::disk('public')->delete($tutorial->thumbnail_path);
            }
        });
    }

    /** @return list<string> */
    public static function allowedCategories(): array
    {
        return ['general', 'getting-started', 'sales', 'inventory'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
