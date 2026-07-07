<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PipelineLeadActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $reactions = ['likes' => 0, 'dislikes' => 0, 'user_reaction' => null];
        if ($this->relationLoaded('reactions')) {
            $reactions['likes'] = $this->reactions->where('reaction', 'like')->count();
            $reactions['dislikes'] = $this->reactions->where('reaction', 'dislike')->count();
            $viewerId = $request->user()?->id;
            if ($viewerId) {
                $reactions['user_reaction'] = $this->reactions->firstWhere('user_id', $viewerId)?->reaction;
            }
        }

        return [
            'id' => $this->id,
            'lead_id' => $this->lead_id,
            'parent_id' => $this->parent_id,
            'user_id' => $this->user_id,
            'type' => $this->type,
            'body' => $this->body,
            'metadata' => $this->metadata,
            'reactions' => $reactions,
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'avatar' => $this->user->avatar,
            ] : null),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
