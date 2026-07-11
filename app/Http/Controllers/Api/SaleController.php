<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaleRequest;
use App\Http\Resources\SaleCollection;
use App\Http\Resources\SaleResource;
use App\Services\Contracts\SaleServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaleController extends Controller
{
    public function __construct(
        protected SaleServiceInterface $saleService,
    ) {}

    public function index(Request $request): SaleCollection
    {
        $businessId = $request->user()->business_id;
        return new SaleCollection($this->saleService->getAll($businessId));
    }

    public function show(int $id): SaleResource
    {
        $sale = $this->saleService->getById($id);
        if (!$sale) {
            abort(404, 'Sale not found');
        }
        return new SaleResource($sale);
    }

    public function store(SaleRequest $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $userId = $request->user()->id;
        $sale = $this->saleService->create($businessId, $userId, $request->validated());
        return response()->json(new SaleResource($sale), 201);
    }

    public function update(SaleRequest $request, int $id): SaleResource
    {
        $sale = $this->saleService->update($id, $request->validated());
        return new SaleResource($sale);
    }

    public function assignCustomer(Request $request, int $id): SaleResource
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
        ]);

        $sale = $this->saleService->getById($id);
        if (!$sale || (int) $sale->business_id !== (int) $request->user()->business_id) {
            abort(404, 'Sale not found');
        }

        $updated = $this->saleService->update($id, ['customer_id' => (int) $data['customer_id']]);

        return new SaleResource($updated->load(['customer', 'saleItems', 'payments']));
    }

    public function destroy(int $id): JsonResponse
    {
        $this->saleService->delete($id);
        return response()->json(null, 204);
    }

    public function daily(Request $request): SaleCollection
    {
        $businessId = $request->user()->business_id;
        $date = $request->query('date');
        return new SaleCollection($this->saleService->getDaily($businessId, $date));
    }

    public function refund(int $id, Request $request): SaleResource
    {
        $sale = $this->saleService->refund($id, $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'exists:sale_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.amount' => ['nullable', 'numeric', 'min:0'],
        ]), $request->user()->id);
        return new SaleResource($sale);
    }

    public function recordPayment(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['nullable', 'string', 'in:cash,mobile_money,card,bank,other'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'amount_tendered' => ['nullable', 'numeric', 'min:0'],
            'change_given' => ['nullable', 'numeric', 'min:0'],
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'attachment' => ['nullable', 'file', 'max:5120', 'mimes:jpg,jpeg,png,pdf,doc,docx,xlsx'],
        ]);

        $attachmentPath = $request->hasFile('attachment')
            ? $request->file('attachment')->store('payment-attachments', 'public')
            : null;

        try {
            $payment = $this->saleService->recordPayment(
                $id,
                (float) $data['amount'],
                $data['payment_method'] ?? 'cash',
                $request->user()->id,
                $data['notes'] ?? null,
                isset($data['amount_tendered']) ? (float) $data['amount_tendered'] : null,
                isset($data['change_given']) ? (float) $data['change_given'] : null,
                $attachmentPath,
                isset($data['shift_id']) ? (int) $data['shift_id'] : null,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $sale = $this->saleService->getById($id)?->load(['payments', 'saleItems', 'customer', 'user', 'business']);

        return response()->json([
            'sale' => new SaleResource($sale),
            'payment' => new \App\Http\Resources\PaymentResource($payment),
        ]);
    }

    public function batch(Request $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $userId = $request->user()->id;

        $data = $request->validate([
            'sales' => ['required', 'array', 'min:1', 'max:50'],
            'sales.*.subtotal' => ['required', 'numeric', 'min:0'],
            'sales.*.tax_total' => ['nullable', 'numeric', 'min:0'],
            'sales.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'sales.*.total_amount' => ['required', 'numeric', 'min:0'],
            'sales.*.amount_paid' => ['nullable', 'numeric', 'min:0'],
            'sales.*.amount_tendered' => ['nullable', 'numeric', 'min:0'],
            'sales.*.change_given' => ['nullable', 'numeric', 'min:0'],
            'sales.*.payment_method' => ['required', 'string', 'in:cash,mobile_money,card,bank,other'],
            'sales.*.shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'sales.*.customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'sales.*.order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'sales.*.sale_date' => ['nullable', 'date'],
            'sales.*.notes' => ['nullable', 'string'],
            'sales.*.items' => ['required', 'array', 'min:1'],
            'sales.*.items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'sales.*.items.*.quantity' => ['required', 'integer', 'min:1'],
            'sales.*.items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'sales.*.items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $results = [];
        $errors = [];

        foreach ($data['sales'] as $i => $saleData) {
            try {
                $sale = $this->saleService->create($businessId, $userId, $saleData);
                $results[] = new SaleResource($sale);
            } catch (\Throwable $e) {
                $errors[] = [
                    'index' => $i,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'synced' => count($results),
            'failed' => count($errors),
            'sales' => $results,
            'errors' => $errors,
        ]);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'exists:sales,id'],
        ]);

        $businessId = $request->user()->business_id;
        $count = $this->saleService->bulkDelete($data['ids'], $businessId);

        return response()->json(['deleted' => $count]);
    }

    public function byShift(int $shiftId, Request $request): SaleCollection
    {
        $businessId = $request->user()->business_id;
        return new SaleCollection($this->saleService->getByShift($businessId, $shiftId));
    }
}
