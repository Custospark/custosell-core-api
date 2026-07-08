<?php

namespace App\Http\Resources;

use App\Services\PipelineService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PipelineBoardMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $pipeline = app(PipelineService::class);

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'role' => $pipeline->normalizeBoardMemberRole((string) $this->role),
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
        ];
    }
}
