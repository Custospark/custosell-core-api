<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class JournalEntryLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entry_id' => $this->entry_id,
            'account_id' => $this->account_id,
            'account_code' => $this->chartOfAccount?->code,
            'account_name' => $this->chartOfAccount?->name,
            'debit_amount' => (float) $this->debit_amount,
            'credit_amount' => (float) $this->credit_amount,
            'description' => $this->description,
        ];
    }
}
