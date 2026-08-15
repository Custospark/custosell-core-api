<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuickNoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'user_id' => $this->user_id,
            'client_uuid' => $this->client_uuid,
            'title' => $this->title,
            'body' => $this->body,
            'color' => $this->color,
            'tag' => $this->tag,
            'is_shared' => (bool) $this->is_shared,
            'is_pinned' => (bool) $this->is_pinned,
            'sort_order' => (int) ($this->sort_order ?? 0),
            'author' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ] : null),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
