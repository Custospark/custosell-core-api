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
            'shift_id' => $this->shift_id,
            'amount' => $this->amount,
            'description' => $this->description,
            'reference' => $this->reference,
            'supplier_tin' => $this->supplier_tin,
            'supplier_invoice_no' => $this->supplier_invoice_no,
            'vat_amount' => $this->vat_amount,
            'vat_claimable' => (bool) $this->vat_claimable,
            'receipt_url' => $this->receipt_path ? url('storage/' . $this->receipt_path) : null,
            'is_recurring' => $this->is_recurring,
            'recurrence_interval' => $this->recurrence_interval,
            'recurrence_end_date' => $this->recurrence_end_date,
            'next_due_date' => $this->next_due_date,
            'expense_date' => $this->expense_date?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
