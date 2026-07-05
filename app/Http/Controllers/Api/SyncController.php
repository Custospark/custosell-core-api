<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Contracts\SyncServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    public function __construct(
        protected SyncServiceInterface $syncService,
    ) {}

    public function pull(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $since = $request->query('since');

        $data = $this->syncService->pull($businessId, $since);

        return response()->json($data);
    }

    public function push(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $payload = $request->validate([
            'categories' => ['sometimes', 'array'],
            'categories.*.id' => ['nullable', 'integer'],
            'categories.*.name' => ['required', 'string'],
            'products' => ['sometimes', 'array'],
            'customers' => ['sometimes', 'array'],
            'expenses' => ['sometimes', 'array'],
            'invoices' => ['sometimes', 'array'],
            'invoices.*.id' => ['nullable', 'integer'],
            'invoices.*.invoice_number' => ['required', 'string'],
            'invoices.*.customer_id' => ['nullable', 'integer'],
            'invoices.*.issue_date' => ['required', 'date'],
            'invoices.*.due_date' => ['required', 'date'],
            'invoices.*.status' => ['required', 'string', 'in:draft,sent,paid,partially_paid,overdue,cancelled,refunded'],
            'invoices.*.subtotal' => ['required', 'numeric'],
            'invoices.*.tax_total' => ['required', 'numeric'],
            'invoices.*.total_amount' => ['required', 'numeric'],
            'invoices.*.amount_paid' => ['required', 'numeric'],
            'products' => ['sometimes', 'array'],
            'customers' => ['sometimes', 'array'],
            'expenses' => ['sometimes', 'array'],
            'sales' => ['sometimes', 'array'],
            'sale_items' => ['sometimes', 'array'],
            'stock_movements' => ['sometimes', 'array'],
        ]);

        $result = $this->syncService->push($businessId, $payload);

        return response()->json($result);
    }

    public function full(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;

        $data = $this->syncService->full($businessId);

        return response()->json($data);
    }
}
