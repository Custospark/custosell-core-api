<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FixedAssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $monthlyDepreciation = 0;
        if ($this->useful_life_months > 0) {
            $monthlyDepreciation = ($this->cost - $this->salvage_value) / $this->useful_life_months;
        }

        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'account_id' => $this->account_id,
            'chart_of_account' => new ChartOfAccountResource($this->whenLoaded('chartOfAccount')),
            'name' => $this->name,
            'cost' => (float) $this->cost,
            'salvage_value' => (float) $this->salvage_value,
            'useful_life_months' => $this->useful_life_months,
            'purchase_date' => $this->purchase_date?->toISOString(),
            'book_value' => (float) $this->book_value,
            'status' => $this->status,
            'monthly_depreciation' => round($monthlyDepreciation, 2),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
