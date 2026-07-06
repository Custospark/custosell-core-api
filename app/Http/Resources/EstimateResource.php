<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EstimateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_id' => $this->business_id,
            'estimate_number' => $this->estimate_number,
            'version' => $this->version,
            'title' => $this->title,
            'status' => $this->status,
            'currency' => $this->currency,
            'customer_id' => $this->customer_id,
            'customer' => new CustomerResource($this->whenLoaded('customer')),
            'pipeline_lead_id' => $this->pipeline_lead_id,
            'project_id' => $this->project_id,
            'invoice_id' => $this->invoice_id,
            'parent_estimate_id' => $this->parent_estimate_id,
            'subtotal' => $this->subtotal,
            'discount_type' => $this->discount_type,
            'discount_value' => $this->discount_value,
            'discount_amount' => $this->discount_amount,
            'tax_rate' => $this->tax_rate,
            'tax_total' => $this->tax_total,
            'total' => $this->total,
            'cost_subtotal' => $this->cost_subtotal,
            'gross_profit' => $this->gross_profit,
            'margin_percent' => $this->margin_percent,
            'valid_until' => $this->valid_until?->toISOString(),
            'notes' => $this->notes,
            'terms' => $this->terms,
            'internal_notes' => $this->internal_notes,
            'sent_at' => $this->sent_at?->toISOString(),
            'approved_at' => $this->approved_at?->toISOString(),
            'approved_by_name' => $this->approved_by_name,
            'rejection_reason' => $this->rejection_reason,
            'email_sent_count' => (int) ($this->email_sent_count ?? 0),
            'last_emailed_at' => $this->last_emailed_at?->toISOString(),
            'created_by' => $this->created_by,
            'created_by_user' => new UserResource($this->whenLoaded('createdBy')),
            'assigned_to' => $this->assigned_to,
            'assigned_to_user' => new UserResource($this->whenLoaded('assignedTo')),
            'line_items' => EstimateLineItemResource::collection($this->whenLoaded('lineItems')),
            'versions' => EstimateVersionResource::collection($this->whenLoaded('versions')),
            'project' => new ProjectResource($this->whenLoaded('project')),
            'invoice' => new InvoiceResource($this->whenLoaded('invoice')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
