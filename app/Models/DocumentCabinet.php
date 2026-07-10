<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DocumentCabinet extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'business_id',
        'name',
        'description',
        'visibility',
        'cover_color',
        'background_type',
        'background_value',
        'sort_order',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function memberLinks(): HasMany
    {
        return $this->hasMany(DocumentCabinetMember::class, 'cabinet_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'document_cabinet_members', 'cabinet_id', 'user_id')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function folders(): HasMany
    {
        return $this->hasMany(DocumentFolder::class, 'cabinet_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'cabinet_id');
    }
}
