<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Resources\InvoiceCollection;
use App\Http\Resources\InvoiceResource;
use App\Services\Contracts\InvoiceServiceInterface;
use App\Services\ReportExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InvoiceController extends Controller
{
    public function __construct(
        protected InvoiceServiceInterface $invoiceService,
        protected ReportExportService $export,
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

    public function downloadPdf(Request $request, int $id): Response
    {
        $invoice = $this->invoiceService->getById($id);
        if (!$invoice) {
            abort(404, 'Invoice not found');
        }

        $invoice->load(['items.product', 'customer', 'createdBy', 'business']);
        $business = $invoice->business;
        $currency = $business->currency ?? 'UGX';
        $balanceDue = max(0, (float) $invoice->total_amount - (float) $invoice->amount_paid);

        $statusLabel = match ($invoice->status) {
            'partially_paid' => 'Partially Paid',
            'cancelled' => 'Cancelled',
            default => ucfirst((string) $invoice->status),
        };

        $filename = $this->export->buildFilename($business, 'invoice-' . $invoice->invoice_number);

        $pdfData = [
            'business' => $business,
            'invoice' => $invoice,
            'formatter' => $this->export,
            'currency' => $currency,
            'balanceDue' => $balanceDue,
            'statusLabel' => $statusLabel,
            'reportTitle' => 'Tax Invoice',
            'reportSubtitle' => sprintf(
                'Invoice #%s · Issued %s · Due %s',
                $invoice->invoice_number,
                $invoice->issue_date?->format('M d, Y'),
                $invoice->due_date?->format('M d, Y'),
            ),
            'reportPurpose' => $balanceDue > 0
                ? 'Please remit payment by the due date shown above.'
                : 'This invoice has been fully paid. Thank you for your business.',
            'accent' => '#1e40af',
            'summaryCards' => [
                ['label' => 'Invoice #', 'value' => $invoice->invoice_number],
                ['label' => 'Status', 'value' => $statusLabel],
                ['label' => 'Total', 'value' => $this->export->formatMoney((float) $invoice->total_amount, $currency)],
                [
                    'label' => 'Balance Due',
                    'value' => $this->export->formatMoney($balanceDue, $currency),
                    'tone' => $balanceDue > 0 ? 'negative' : 'positive',
                ],
            ],
        ];

        return $this->export->downloadPdf('invoices.pdf', $pdfData, $filename, 'portrait');
    }

    public function recordPayment(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['nullable', 'string', 'in:cash,mobile_money,card,other'],
        ]);

        $invoice = $this->invoiceService->recordPayment(
            $id,
            (float) $request->amount,
            $request->input('payment_method', 'cash'),
        );
        return response()->json(new InvoiceResource($invoice));
    }
}
