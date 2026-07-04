<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GeneralLedgerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'account_id' => $this->account_id,
            'account_code' => $this->chartOfAccount?->code,
            'account_name' => $this->chartOfAccount?->name,
            'period_id' => $this->period_id,
            'opening_balance' => (float) $this->opening_balance,
            'total_debits' => (float) $this->total_debits,
            'total_credits' => (float) $this->total_credits,
            'closing_balance' => (float) $this->closing_balance,
        ];
    }
}
