<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BoardWallPostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'board_id' => $this->board_id,
            'created_by' => $this->created_by,
            'creator' => $this->whenLoaded('creator', fn() => [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ]),
            'type' => $this->type,
            'title' => $this->title,
            'content' => $this->content,
            'photo_url' => $this->photo,
            'staff_id' => $this->staff_id,
            'staff' => $this->whenLoaded('staff', fn() => $this->staff ? [
                'id' => $this->staff->id,
                'name' => $this->staff->name,
                'avatar' => $this->staff->avatar,
            ] : null),
            'author_name' => $this->author_name,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'pinned' => $this->pinned,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
