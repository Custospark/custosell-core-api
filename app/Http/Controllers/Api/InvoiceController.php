<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Resources\InvoiceCollection;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\PaymentResource;
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
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['nullable', 'string', 'in:cash,mobile_money,card,bank,other'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'amount_tendered' => ['nullable', 'numeric', 'min:0'],
            'change_given' => ['nullable', 'numeric', 'min:0'],
            'attachment' => ['nullable', 'file', 'max:5120', 'mimes:jpg,jpeg,png,pdf,doc,docx,xlsx'],
        ]);

        $attachmentPath = $request->hasFile('attachment')
            ? $request->file('attachment')->store('payment-attachments', 'public')
            : null;

        try {
            $invoice = $this->invoiceService->recordPayment(
                $id,
                (float) $data['amount'],
                $request->input('payment_method', 'cash'),
                $request->user()->id,
                $data['notes'] ?? null,
                isset($data['amount_tendered']) ? (float) $data['amount_tendered'] : null,
                isset($data['change_given']) ? (float) $data['change_given'] : null,
                $attachmentPath,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'invoice' => new InvoiceResource($invoice['invoice']),
            'payment' => new PaymentResource($invoice['payment']),
        ]);
    }
}
