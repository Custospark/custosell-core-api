<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SendDocumentEmailRequest;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Resources\InvoiceCollection;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\PaymentResource;
use App\Services\Contracts\InvoiceServiceInterface;
use App\Services\CustomerDocumentEmailService;
use App\Services\InvoicePdfBuilder;
use App\Services\ReportExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InvoiceController extends Controller
{
    public function __construct(
        protected InvoiceServiceInterface $invoiceService,
        protected ReportExportService $export,
        protected InvoicePdfBuilder $invoicePdfBuilder,
        protected CustomerDocumentEmailService $documentEmailService,
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

        if ((int) $invoice->business_id !== (int) $request->user()->business_id) {
            abort(404, 'Invoice not found');
        }

        $business = $request->user()->business;
        $pdfConfig = $this->invoicePdfBuilder->build($invoice, $business);

        return $this->export->downloadPdf(
            $pdfConfig['view'],
            $pdfConfig['data'],
            $pdfConfig['filename'],
            $pdfConfig['orientation'],
        );
    }

    public function email(SendDocumentEmailRequest $request, int $id): JsonResponse
    {
        $invoice = $this->invoiceService->getById($id);
        if (!$invoice) {
            abort(404, 'Invoice not found');
        }

        if ((int) $invoice->business_id !== (int) $request->user()->business_id) {
            abort(404, 'Invoice not found');
        }

        $invoice->loadMissing(['customer']);
        $to = trim((string) ($request->validated('to') ?? ''));
        if ($to === '') {
            $to = $this->documentEmailService->resolveCustomerEmail($invoice->customer) ?? '';
        }

        if ($to === '') {
            return response()->json([
                'message' => 'No recipient email. Add a customer email or enter one manually.',
            ], 422);
        }

        try {
            $result = $this->documentEmailService->sendInvoice(
                $invoice,
                $request->user()->business,
                $to,
                $request->validated('message'),
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json($result);
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
            $invoice = $this->invoiceService->recordPayment(
                $id,
                (float) $data['amount'],
                $request->input('payment_method', 'cash'),
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

        return response()->json([
            'invoice' => new InvoiceResource($invoice['invoice']),
            'payment' => new PaymentResource($invoice['payment']),
        ]);
    }
}
