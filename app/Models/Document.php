<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'business_id',
        'folder_id',
        'type',
        'title',
        'description',
        'visibility',
        'customer_id',
        'project_id',
        'url',
        'file_name',
        'file_path',
        'mime_type',
        'file_size',
        'uploaded_by',
        'views_count',
        'downloads_count',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'views_count' => 'integer',
            'downloads_count' => 'integer',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(DocumentFolder::class, 'folder_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function memberLinks(): HasMany
    {
        return $this->hasMany(DocumentMember::class, 'document_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'document_members', 'document_id', 'user_id')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(DocumentTag::class, 'document_tag_pivot', 'document_id', 'document_tag_id');
    }
}
