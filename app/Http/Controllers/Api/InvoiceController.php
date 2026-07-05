<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Resources\InvoiceCollection;
use App\Http\Resources\InvoiceResource;
use App\Services\Contracts\InvoiceServiceInterface;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class InvoiceController extends Controller
{
    public function __construct(
        protected InvoiceServiceInterface $invoiceService,
    ) {}

    public function index(Request $request): InvoiceCollection
    {
        $businessId = $request->user()->business_id;
        $filters = $request->only(['status', 'customer_id', 'date_from', 'date_to']);
        return new InvoiceCollection(
            $this->invoiceService->getAll($businessId, $filters)
        );
    }

    public function show(int $id): InvoiceResource
    {
        $invoice = $this->invoiceService->getById($id);
        if (!$invoice) {
            abort(404, 'Invoice not found');
        }
        return new InvoiceResource($invoice);
    }

    public function store(StoreInvoiceRequest $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $userId = $request->user()->id;
        $data = $request->validated();

        $invoice = $this->invoiceService->create($businessId, $userId, $data);
        return response()->json(new InvoiceResource($invoice), 201);
    }

    public function update(StoreInvoiceRequest $request, int $id): InvoiceResource
    {
        $data = $request->validated();
        $invoice = $this->invoiceService->update($id, $data);
        return new InvoiceResource($invoice);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->invoiceService->delete($id);
        return response()->json(null, 204);
    }

    public function send(int $id): JsonResponse
    {
        $invoice = $this->invoiceService->send($id);
        return response()->json(new InvoiceResource($invoice));
    }

    public function downloadPdf(int $id): BinaryFileResponse
    {
        $invoice = $this->invoiceService->getById($id);
        if (!$invoice) {
            abort(404, 'Invoice not found');
        }

        $business = $invoice->business;

        $pdf = Pdf::loadView('invoices.pdf', [
            'invoice' => $invoice->load('items.product', 'customer', 'createdBy'),
            'business' => $business,
            'formatter' => app(\App\Services\ReportExportService::class),
            'reportTitle' => 'INVOICE #' . $invoice->invoice_number,
            'accent' => '#1e40af',
        ]);
        $pdf->setPaper('a4', 'portrait');

        return $pdf->download("invoice-{$invoice->invoice_number}.pdf");
    }

    public function recordPayment(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $invoice = $this->invoiceService->recordPayment($id, (float) $request->amount);
        return response()->json(new InvoiceResource($invoice));
    }
}
