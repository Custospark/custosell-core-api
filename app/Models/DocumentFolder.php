<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DocumentFolder extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'business_id',
        'parent_id',
        'name',
        'description',
        'visibility',
        'cover_color',
        'depth',
        'created_by',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'depth' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function memberLinks(): HasMany
    {
        return $this->hasMany(DocumentFolderMember::class, 'folder_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'document_folder_members', 'folder_id', 'user_id')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'folder_id');
    }
}
