<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'expense_category_id' => $this->expense_category_id,
            'expense_category' => new ExpenseCategoryResource($this->whenLoaded('expenseCategory')),
            'recorded_by' => $this->recorded_by,
            'recorded_by_user' => new UserResource($this->whenLoaded('recordedBy')),
            'amount' => $this->amount,
            'description' => $this->description,
            'reference' => $this->reference,
            'expense_date' => $this->expense_date,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
