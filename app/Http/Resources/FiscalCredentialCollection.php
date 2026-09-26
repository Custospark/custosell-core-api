<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;

class FiscalCredentialCollection extends ResourceCollection
{
    public function toArray($request): array
    {
        return [
            'data' => FiscalCredentialResource::collection($this->collection),
        ];
    }
}
