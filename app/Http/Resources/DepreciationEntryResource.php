<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DepreciationEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_id' => $this->asset_id,
            'period_id' => $this->period_id,
            'journal_entry_id' => $this->journal_entry_id,
            'amount' => (float) $this->amount,
            'accumulated_depreciation' => (float) $this->accumulated_depreciation,
            'book_value_after' => (float) $this->book_value_after,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
