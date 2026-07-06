<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Estimate;
use App\Models\EstimateLineItem;
use App\Models\EstimateTemplate;
use App\Models\EstimateVersion;
use App\Models\Invoice;
use App\Models\Project;
use App\Repositories\Contracts\EstimateRepositoryInterface;
use App\Services\Contracts\EstimateServiceInterface;
use App\Services\Contracts\InvoiceServiceInterface;
use App\Services\Contracts\ProjectServiceInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

class EstimateService implements EstimateServiceInterface
{
    public function __construct(
        protected EstimateRepositoryInterface $estimateRepository,
        protected InvoiceServiceInterface $invoiceService,
        protected ProjectServiceInterface $projectService,
    ) {}

    public function getAll(int $businessId, array $filters = []): Collection
    {
        return $this->estimateRepository->all($businessId, $filters);
    }

    public function getById(int $id): ?Estimate
    {
        return $this->estimateRepository->find($id);
    }

    public function create(int $businessId, int $userId, array $data): Estimate
    {
        $maxAttempts = 3;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return $this->attemptCreate($businessId, $userId, $data);
            } catch (QueryException $e) {
                if ($attempt === $maxAttempts || !str_contains($e->getMessage(), 'Duplicate entry')) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Failed to create estimate after ' . $maxAttempts . ' attempts');
    }

    protected function attemptCreate(int $businessId, int $userId, array $data): Estimate
    {
        return DB::transaction(function () use ($businessId, $userId, $data) {
            $business = Business::findOrFail($businessId);
            $estimateNumber = DocumentNumberGenerator::estimateNumber($business, Estimate::class, 'estimate_number');
            $totals = $this->calculateTotals($data['line_items'] ?? [], $data);

            $estimate = $this->estimateRepository->create([
                'business_id' => $businessId,
                'customer_id' => $data['customer_id'] ?? null,
                'pipeline_lead_id' => $data['pipeline_lead_id'] ?? null,
                'parent_estimate_id' => $data['parent_estimate_id'] ?? null,
                'estimate_number' => $estimateNumber,
                'version' => 1,
                'title' => $data['title'],
                'status' => 'draft',
                'currency' => $data['currency'] ?? $business->currency ?? 'UGX',
                'subtotal' => $totals['subtotal'],
                'discount_type' => $data['discount_type'] ?? null,
                'discount_value' => $data['discount_value'] ?? 0,
                'discount_amount' => $totals['discount_amount'],
                'tax_rate' => $data['tax_rate'] ?? 0,
                'tax_total' => $totals['tax_total'],
                'total' => $totals['total'],
                'cost_subtotal' => $totals['cost_subtotal'],
                'gross_profit' => $totals['gross_profit'],
                'margin_percent' => $totals['margin_percent'],
                'valid_until' => $data['valid_until'] ?? null,
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? null,
                'internal_notes' => $data['internal_notes'] ?? null,
                'created_by' => $userId,
                'assigned_to' => $data['assigned_to'] ?? null,
            ]);

            $this->syncLineItems($estimate, $data['line_items'] ?? []);

            if (!empty($data['pipeline_lead_id'])) {
                \App\Models\PipelineLead::query()
                    ->where('business_id', $businessId)
                    ->whereKey($data['pipeline_lead_id'])
                    ->update(['estimate_id' => $estimate->id]);
            }

            return $estimate->fresh(['customer', 'createdBy', 'assignedTo', 'lineItems.product']);
        });
    }

    public function update(int $id, array $data): Estimate
    {
        $estimate = $this->estimateRepository->find($id);
        if (!$estimate) {
            throw new \RuntimeException('Estimate not found');
        }

        if (!in_array($estimate->status, ['draft', 'rejected'], true)) {
            throw new \RuntimeException('Only draft or rejected estimates can be updated');
        }

        return DB::transaction(function () use ($estimate, $data) {
            if (isset($data['line_items'])) {
                $totals = $this->calculateTotals($data['line_items'], $data);
                $data = array_merge($data, $totals);
                $estimate->lineItems()->delete();
                $this->syncLineItems($estimate, $data['line_items']);
                unset($data['line_items']);
            }

            return $this->estimateRepository->update($estimate, $data)
                ->load(['customer', 'createdBy', 'assignedTo', 'lineItems.product']);
        });
    }

    public function delete(int $id): bool
    {
        $estimate = $this->estimateRepository->find($id);
        if (!$estimate) {
            throw new \RuntimeException('Estimate not found');
        }

        if ($estimate->status !== 'draft') {
            throw new \RuntimeException('Only draft estimates can be deleted');
        }

        return $this->estimateRepository->delete($estimate);
    }

    public function send(int $id, int $userId, ?string $changeSummary = null): Estimate
    {
        $estimate = $this->estimateRepository->find($id);
        if (!$estimate) {
            throw new \RuntimeException('Estimate not found');
        }

        if (!in_array($estimate->status, ['draft', 'rejected'], true)) {
            throw new \RuntimeException('Only draft or rejected estimates can be sent');
        }

        return DB::transaction(function () use ($estimate, $userId, $changeSummary) {
            $this->createVersionSnapshot($estimate, $userId, $changeSummary ?? 'Sent to customer');

            $estimate = $this->estimateRepository->update($estimate, [
                'status' => 'sent',
                'sent_at' => now(),
                'rejection_reason' => null,
            ]);

            return $estimate->load(['customer', 'createdBy', 'lineItems.product', 'versions']);
        });
    }

    public function approve(int $id, ?string $approvedByName = null): Estimate
    {
        $estimate = $this->estimateRepository->find($id);
        if (!$estimate) {
            throw new \RuntimeException('Estimate not found');
        }

        if (!in_array($estimate->status, ['draft', 'sent'], true)) {
            throw new \RuntimeException('Only draft or sent estimates can be approved');
        }

        return $this->estimateRepository->update($estimate, [
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by_name' => $approvedByName,
            'rejection_reason' => null,
        ])->load(['customer', 'createdBy', 'lineItems.product']);
    }

    public function reject(int $id, string $reason): Estimate
    {
        $estimate = $this->estimateRepository->find($id);
        if (!$estimate) {
            throw new \RuntimeException('Estimate not found');
        }

        if ($estimate->status !== 'sent') {
            throw new \RuntimeException('Only sent estimates can be rejected');
        }

        return $this->estimateRepository->update($estimate, [
            'status' => 'rejected',
            'rejection_reason' => $reason,
            'approved_at' => null,
            'approved_by_name' => null,
        ])->load(['customer', 'createdBy', 'lineItems.product']);
    }

    public function duplicate(int $id, int $userId): Estimate
    {
        $source = $this->estimateRepository->find($id);
        if (!$source) {
            throw new \RuntimeException('Estimate not found');
        }

        $lineItems = $source->lineItems->map(fn (EstimateLineItem $item) => [
            'product_id' => $item->product_id,
            'sort_order' => $item->sort_order,
            'type' => $item->type,
            'description' => $item->description,
            'quantity' => $item->quantity,
            'unit_cost' => $item->unit_cost,
            'unit_price' => $item->unit_price,
            'markup_type' => $item->markup_type,
            'markup_value' => $item->markup_value,
            'is_billable' => $item->is_billable,
        ])->all();

        return $this->create($source->business_id, $userId, [
            'customer_id' => $source->customer_id,
            'pipeline_lead_id' => $source->pipeline_lead_id,
            'parent_estimate_id' => $source->id,
            'title' => $source->title . ' (Copy)',
            'currency' => $source->currency,
            'discount_type' => $source->discount_type,
            'discount_value' => $source->discount_value,
            'tax_rate' => $source->tax_rate,
            'valid_until' => $source->valid_until,
            'notes' => $source->notes,
            'terms' => $source->terms,
            'internal_notes' => $source->internal_notes,
            'assigned_to' => $source->assigned_to,
            'line_items' => $lineItems,
        ]);
    }

    public function createRevision(int $id, int $userId, array $data): Estimate
    {
        $source = $this->estimateRepository->find($id);
        if (!$source) {
            throw new \RuntimeException('Estimate not found');
        }

        return DB::transaction(function () use ($source, $userId, $data) {
            $this->createVersionSnapshot($source, $userId, $data['change_summary'] ?? 'Revision created');

            $lineItems = $data['line_items'] ?? $source->lineItems->map(fn (EstimateLineItem $item) => [
                'product_id' => $item->product_id,
                'sort_order' => $item->sort_order,
                'type' => $item->type,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_cost' => $item->unit_cost,
                'unit_price' => $item->unit_price,
                'markup_type' => $item->markup_type,
                'markup_value' => $item->markup_value,
                'is_billable' => $item->is_billable,
            ])->all();

            $totals = $this->calculateTotals($lineItems, array_merge($source->toArray(), $data));

            $estimate = $this->estimateRepository->update($source, [
                'version' => $source->version + 1,
                'status' => 'draft',
                'title' => $data['title'] ?? $source->title,
                'customer_id' => $data['customer_id'] ?? $source->customer_id,
                'discount_type' => $data['discount_type'] ?? $source->discount_type,
                'discount_value' => $data['discount_value'] ?? $source->discount_value,
                'tax_rate' => $data['tax_rate'] ?? $source->tax_rate,
                'valid_until' => $data['valid_until'] ?? $source->valid_until,
                'notes' => $data['notes'] ?? $source->notes,
                'terms' => $data['terms'] ?? $source->terms,
                'internal_notes' => $data['internal_notes'] ?? $source->internal_notes,
                'subtotal' => $totals['subtotal'],
                'discount_amount' => $totals['discount_amount'],
                'tax_total' => $totals['tax_total'],
                'total' => $totals['total'],
                'cost_subtotal' => $totals['cost_subtotal'],
                'gross_profit' => $totals['gross_profit'],
                'margin_percent' => $totals['margin_percent'],
                'sent_at' => null,
                'approved_at' => null,
                'approved_by_name' => null,
                'rejection_reason' => null,
            ]);

            $estimate->lineItems()->delete();
            $this->syncLineItems($estimate, $lineItems);

            return $estimate->fresh(['customer', 'createdBy', 'lineItems.product', 'versions']);
        });
    }

    public function convertToInvoice(int $id, int $userId, array $options = []): Invoice
    {
        $estimate = $this->estimateRepository->find($id);
        if (!$estimate) {
            throw new \RuntimeException('Estimate not found');
        }

        if (!in_array($estimate->status, ['sent', 'approved'], true)) {
            throw new \RuntimeException('Only sent or approved estimates can be converted to invoices');
        }

        if ($estimate->invoice_id) {
            throw new \RuntimeException('Estimate has already been converted to an invoice');
        }

        return DB::transaction(function () use ($estimate, $userId, $options) {
            $issueDate = $options['issue_date'] ?? now()->toDateString();
            $dueDate = $options['due_date'] ?? ($estimate->valid_until?->toDateString() ?? now()->addDays(30)->toDateString());

            $items = $estimate->lineItems
                ->filter(fn (EstimateLineItem $item) => $item->is_billable)
                ->map(fn (EstimateLineItem $item) => [
                    'product_id' => $item->product_id,
                    'description' => $item->description,
                    'quantity' => (float) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                ])
                ->values()
                ->all();

            if (empty($items)) {
                throw new \RuntimeException('Estimate has no billable line items to invoice');
            }

            $invoice = $this->invoiceService->create($estimate->business_id, $userId, [
                'customer_id' => $estimate->customer_id,
                'issue_date' => $issueDate,
                'due_date' => $dueDate,
                'items' => $items,
                'tax_total' => (float) $estimate->tax_total,
                'notes' => $estimate->notes,
            ]);

            $invoice->update(['estimate_id' => $estimate->id]);

            $this->estimateRepository->update($estimate, [
                'invoice_id' => $invoice->id,
                'status' => 'converted',
            ]);

            if (!empty($options['send'])) {
                $invoice = $this->invoiceService->send($invoice->id);
            }

            return $invoice->fresh(['customer', 'items.product', 'estimate']);
        });
    }

    public function convertToProject(int $id, int $userId, array $options = []): Project
    {
        $estimate = $this->estimateRepository->find($id);
        if (!$estimate) {
            throw new \RuntimeException('Estimate not found');
        }

        if ($estimate->project_id) {
            throw new \RuntimeException('Estimate has already been converted to a project');
        }

        if (!in_array($estimate->status, ['sent', 'approved', 'converted'], true)) {
            throw new \RuntimeException('Only sent, approved, or converted estimates can become projects');
        }

        return DB::transaction(function () use ($estimate, $userId, $options) {
            $project = $this->projectService->create($estimate->business_id, $userId, [
                'customer_id' => $estimate->customer_id,
                'estimate_id' => $estimate->id,
                'pipeline_lead_id' => $estimate->pipeline_lead_id,
                'name' => $options['name'] ?? $estimate->title,
                'status' => $options['status'] ?? 'planning',
                'currency' => $estimate->currency,
                'budget_revenue' => (float) $estimate->total,
                'budget_cost' => (float) $estimate->cost_subtotal,
                'start_date' => $options['start_date'] ?? now()->toDateString(),
                'due_date' => $options['due_date'] ?? $estimate->valid_until?->toDateString(),
                'description' => $estimate->notes,
                'manager_id' => $estimate->assigned_to,
            ]);

            $this->estimateRepository->update($estimate, [
                'project_id' => $project->id,
                'status' => 'converted',
            ]);

            return $project->fresh(['customer', 'estimate', 'tasks']);
        });
    }

    public function analyticsSummary(int $businessId): array
    {
        return $this->estimateRepository->analyticsSummary($businessId);
    }

    public function getTemplates(int $businessId): Collection
    {
        return $this->estimateRepository->templates($businessId);
    }

    public function createTemplate(int $businessId, int $userId, array $data): EstimateTemplate
    {
        return $this->estimateRepository->createTemplate([
            'business_id' => $businessId,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'line_items_template' => $data['line_items_template'],
            'terms' => $data['terms'] ?? null,
            'default_tax_rate' => $data['default_tax_rate'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
            'created_by' => $userId,
        ]);
    }

    public function updateTemplate(int $id, array $data): EstimateTemplate
    {
        $template = $this->estimateRepository->findTemplate($id);
        if (!$template) {
            throw new \RuntimeException('Estimate template not found');
        }

        return $this->estimateRepository->updateTemplate($template, $data);
    }

    public function deleteTemplate(int $id): bool
    {
        $template = $this->estimateRepository->findTemplate($id);
        if (!$template) {
            throw new \RuntimeException('Estimate template not found');
        }

        return $this->estimateRepository->deleteTemplate($template);
    }

    /**
     * @param  list<array<string, mixed>>  $lineItems
     * @return array<string, float>
     */
    protected function calculateTotals(array $lineItems, array $data): array
    {
        $subtotal = 0;
        $costSubtotal = 0;

        foreach ($lineItems as $item) {
            $calculated = $this->calculateLineItem($item);
            if (($item['is_billable'] ?? true) !== false) {
                $subtotal += $calculated['total_price'];
            }
            $costSubtotal += $calculated['total_cost'];
        }

        $discountType = $data['discount_type'] ?? null;
        $discountValue = (float) ($data['discount_value'] ?? 0);
        $discountAmount = 0;

        if ($discountType === 'percent' && $discountValue > 0) {
            $discountAmount = round($subtotal * ($discountValue / 100), 2);
        } elseif ($discountType === 'fixed' && $discountValue > 0) {
            $discountAmount = min($discountValue, $subtotal);
        }

        $taxable = max(0, $subtotal - $discountAmount);
        $taxRate = (float) ($data['tax_rate'] ?? 0);
        $taxTotal = round($taxable * ($taxRate / 100), 2);
        $total = $taxable + $taxTotal;

        $revenue = $taxable;
        $grossProfit = round($revenue - $costSubtotal, 2);
        $marginPercent = $revenue > 0
            ? round(($grossProfit / $revenue) * 100, 2)
            : 0;

        return [
            'subtotal' => round($subtotal, 2),
            'discount_amount' => round($discountAmount, 2),
            'tax_total' => $taxTotal,
            'total' => round($total, 2),
            'cost_subtotal' => round($costSubtotal, 2),
            'gross_profit' => $grossProfit,
            'margin_percent' => $marginPercent,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{unit_price: float, total_cost: float, total_price: float}
     */
    protected function calculateLineItem(array $item): array
    {
        $qty = (float) ($item['quantity'] ?? 1);
        $unitCost = (float) ($item['unit_cost'] ?? 0);
        $markupType = $item['markup_type'] ?? 'none';
        $markupValue = (float) ($item['markup_value'] ?? 0);

        $unitPrice = match ($markupType) {
            'percent' => $unitCost * (1 + ($markupValue / 100)),
            'fixed' => $unitCost + $markupValue,
            default => (float) ($item['unit_price'] ?? 0),
        };

        return [
            'unit_price' => round($unitPrice, 2),
            'total_cost' => round($qty * $unitCost, 2),
            'total_price' => round($qty * $unitPrice, 2),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lineItems
     */
    protected function syncLineItems(Estimate $estimate, array $lineItems): void
    {
        foreach ($lineItems as $index => $item) {
            $calculated = $this->calculateLineItem($item);

            EstimateLineItem::create([
                'estimate_id' => $estimate->id,
                'product_id' => $item['product_id'] ?? null,
                'sort_order' => $item['sort_order'] ?? $index,
                'type' => $item['type'] ?? 'other',
                'description' => $item['description'],
                'quantity' => $item['quantity'] ?? 1,
                'unit_cost' => $item['unit_cost'] ?? 0,
                'unit_price' => $calculated['unit_price'],
                'markup_type' => $item['markup_type'] ?? 'none',
                'markup_value' => $item['markup_value'] ?? 0,
                'total_cost' => $calculated['total_cost'],
                'total_price' => $calculated['total_price'],
                'is_billable' => $item['is_billable'] ?? true,
            ]);
        }
    }

    protected function createVersionSnapshot(Estimate $estimate, int $userId, ?string $changeSummary): void
    {
        $estimate->loadMissing(['lineItems', 'customer']);

        EstimateVersion::create([
            'estimate_id' => $estimate->id,
            'version' => $estimate->version,
            'snapshot' => [
                'estimate' => $estimate->toArray(),
                'line_items' => $estimate->lineItems->toArray(),
                'customer' => $estimate->customer?->only(['id', 'name', 'email', 'phone']),
            ],
            'created_by' => $userId,
            'change_summary' => $changeSummary,
            'created_at' => now(),
        ]);
    }
}
