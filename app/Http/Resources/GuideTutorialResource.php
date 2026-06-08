<?php

namespace App\Http\Resources;

use App\Services\Guide\VideoThumbnailResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\GuideTutorial */
class GuideTutorialResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $externalThumb = $this->thumbnail_url;
        $hasUploaded = $this->thumbnail_path !== null && trim((string) $this->thumbnail_path) !== '';
        $hasExternal = $externalThumb !== null && trim((string) $externalThumb) !== '';

        $videoPreview = null;
        if (! $hasUploaded && ! $hasExternal) {
            $videoPreview = VideoThumbnailResolver::resolve($this->video_url);
        }

        $uploadedUrl = $hasUploaded ? asset('storage/'.$this->thumbnail_path) : null;

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'title' => $this->title,
            'description' => $this->description,
            'video_url' => $this->video_url,
            'thumbnail_path' => $this->thumbnail_path,
            'thumbnail_url' => $hasExternal ? $externalThumb : null,
            'thumbnail_upload_url' => $uploadedUrl,
            'thumbnail_video_preview_url' => $videoPreview,
            'banner_image_url' => $this->banner_image_url,
            'category' => $this->category,
            'sort_order' => $this->sort_order,
            'is_published' => $this->is_published,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
