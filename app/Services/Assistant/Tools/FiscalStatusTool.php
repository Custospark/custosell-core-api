<?php

declare(strict_types=1);

namespace App\Services\Assistant\Tools;

use App\Models\Invoice;
use App\Models\Sale;
use App\Models\User;
use App\Services\ModuleAccessService;

class FiscalStatusTool extends AbstractAssistantTool
{
    public function name(): string
    {
        return 'fiscal_status';
    }

    public function description(): string
    {
        return 'Recent sales and invoices whose fiscalization is pending or failed. No payload contents.';
    }

    public function parameters(): array
    {
        return [
            'limit' => ['type' => 'int', 'required' => false, 'min' => 1, 'max' => 10, 'default' => 5],
        ];
    }

    public function requiredModule(): string
    {
        return 'efris';
    }

    public function endpoint(): string
    {
        return 'GET /sales + GET /invoices (fiscal_status filter)';
    }

    public function execute(User $user, array $args): array
    {
        if ($denied = $this->denied($user)) {
            return $denied;
        }

        $businessId = $this->businessId($user);
        $limit = $this->argInt($args, 'limit', 5, 1, 10);

        $sales = Sale::where('business_id', $businessId)
            ->whereIn('fiscal_status', ['pending', 'failed'])
            ->latest('id')
            ->take($limit)
            ->get(['receipt_number', 'total_amount', 'fiscal_status', 'fiscal_last_error'])
            ->map(fn ($sale) => [
                'type' => 'sale',
                'reference' => $sale->receipt_number,
                'total' => round((float) $sale->total_amount, 2),
                'status' => $sale->fiscal_status,
                'error' => $sale->fiscal_status === 'failed' ? $sale->fiscal_last_error : null,
            ]);

        $invoices = Invoice::where('business_id', $businessId)
            ->whereIn('fiscal_status', ['pending', 'failed'])
            ->latest('id')
            ->take($limit)
            ->get(['invoice_number', 'total_amount', 'fiscal_status', 'fiscal_last_error'])
            ->map(fn ($invoice) => [
                'type' => 'invoice',
                'reference' => $invoice->invoice_number,
                'total' => round((float) $invoice->total_amount, 2),
                'status' => $invoice->fiscal_status,
                'error' => $invoice->fiscal_status === 'failed' ? $invoice->fiscal_last_error : null,
            ]);

        $this->audit($user, "limit={$limit}");

        return [
            'documents' => $sales->concat($invoices)->take($limit)->values()->all(),
        ];
    }
}
