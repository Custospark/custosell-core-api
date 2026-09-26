<?php

declare(strict_types=1);

namespace App\Services\Assistant\Tools;

use App\Models\User;
use App\Services\Contracts\InvoiceServiceInterface;
use App\Services\ModuleAccessService;

class OutstandingInvoicesTool extends AbstractAssistantTool
{
    public function __construct(
        ModuleAccessService $moduleAccess,
        private InvoiceServiceInterface $invoices,
    ) {
        parent::__construct($moduleAccess);
    }

    public function name(): string
    {
        return 'outstanding_invoices';
    }

    public function description(): string
    {
        return 'Unpaid or partially paid sales invoices, largest balance first. No customer contact details.';
    }

    public function parameters(): array
    {
        return [
            'limit' => ['type' => 'int', 'required' => false, 'min' => 1, 'max' => 10, 'default' => 5],
        ];
    }

    public function requiredModule(): string
    {
        return 'sales';
    }

    public function endpoint(): string
    {
        return 'GET /invoices';
    }

    public function execute(User $user, array $args): array
    {
        if ($denied = $this->denied($user)) {
            return $denied;
        }

        $limit = $this->argInt($args, 'limit', 5, 1, 10);
        $open = $this->invoices
            ->getAll($this->businessId($user))
            ->filter(fn ($invoice) => in_array($invoice->payment_status, ['unpaid', 'partially_paid', 'partial', 'sent', 'overdue'], true))
            ->map(fn ($invoice) => [
                'number' => $invoice->invoice_number,
                'customer' => $invoice->customer?->name,
                'total' => round((float) $invoice->total_amount, 2),
                'paid' => round((float) ($invoice->amount_paid ?? 0), 2),
                'balance' => round((float) $invoice->total_amount - (float) ($invoice->amount_paid ?? 0), 2),
                'status' => $invoice->payment_status,
            ])
            ->sortByDesc('balance')
            ->take($limit);

        $this->audit($user, "limit={$limit}");

        return ['invoices' => $open->values()->all()];
    }
}
