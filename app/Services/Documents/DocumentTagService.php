<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\DocumentTag;
use Illuminate\Support\Str;

class DocumentTagService
{
  private const TAG_COLORS = [
        '#6366f1', '#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#64748b',
    ];

    /** @return list<array{id: int, name: string, slug: string, color: string|null}> */
    public function list(int $businessId, ?string $query = null): array
    {
        $builder = DocumentTag::query()
            ->where('business_id', $businessId)
            ->orderBy('name');

        if ($query !== null && trim($query) !== '') {
            $builder->where('name', 'like', '%'.trim($query).'%');
        }

        return $builder->get()
            ->map(fn (DocumentTag $tag) => $this->serializeTag($tag))
            ->values()
            ->all();
    }

    /** @return array{id: int, name: string, slug: string, color: string|null} */
    public function serializeTag(DocumentTag $tag): array
    {
        return [
            'id' => $tag->id,
            'name' => $tag->name,
            'slug' => $tag->slug,
            'color' => $tag->color ?? $this->colorForSlug($tag->slug),
        ];
    }

    /** @return array{id: int, name: string, slug: string, color: string|null} */
    public function create(int $businessId, string $name): array
    {
        $normalized = $this->normalizeName($name);
        $slug = Str::slug($normalized);

        $tag = DocumentTag::query()->firstOrCreate(
            ['business_id' => $businessId, 'slug' => $slug],
            ['name' => $normalized, 'color' => $this->colorForSlug($slug)],
        );

        if ($tag->color === null) {
            $tag->color = $this->colorForSlug($slug);
            $tag->save();
        }

        return $this->serializeTag($tag);
    }

    /** @param  list<string>  $tagNames */
    public function syncDocumentTags(Document $document, int $businessId, array $tagNames): void
    {
        $ids = collect($tagNames)
            ->map(fn (string $name) => trim($name))
            ->filter(fn (string $name) => $name !== '')
            ->unique()
            ->map(fn (string $name) => $this->create($businessId, $name)['id'])
            ->values()
            ->all();

        $document->tags()->sync($ids);
    }

    protected function normalizeName(string $name): string
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            abort(422, 'Tag name is required.');
        }

        return mb_substr($trimmed, 0, 50);
    }

    protected function colorForSlug(string $slug): string
    {
        $index = abs(crc32($slug)) % count(self::TAG_COLORS);

        return self::TAG_COLORS[$index];
    }
}
