<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SendDocumentEmailRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\CustomerDocumentEmailService;
use App\Services\PaymentReceiptPdfBuilder;
use App\Services\ReportExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PaymentController extends Controller
{
    public function __construct(
        protected ReportExportService $export,
        protected PaymentReceiptPdfBuilder $paymentReceiptPdfBuilder,
        protected CustomerDocumentEmailService $documentEmailService,
    ) {}

    public function show(Request $request, int $id): PaymentResource
    {
        $payment = $this->findVisiblePaymentOrFail((int) $request->user()->business_id, $id);

        return new PaymentResource($payment);
    }

    public function downloadReceiptPdf(Request $request, int $id): Response
    {
        $payment = $this->findVisiblePaymentOrFail((int) $request->user()->business_id, $id);

        $business = $request->user()->business;
        $pdfConfig = $this->paymentReceiptPdfBuilder->build($payment, $business);

        return $this->export->downloadPdf(
            $pdfConfig['view'],
            $pdfConfig['data'],
            $pdfConfig['filename'],
            $pdfConfig['orientation'],
        );
    }

    public function emailReceipt(SendDocumentEmailRequest $request, int $id): JsonResponse
    {
        // Email remains owner-only (seller records and sends).
        $payment = Payment::with(['payable.customer'])
            ->where('business_id', $request->user()->business_id)
            ->findOrFail($id);

        $to = trim((string) ($request->validated('to') ?? ''));
        if ($to === '') {
            $customer = $payment->payable?->customer ?? null;
            $to = $this->documentEmailService->resolveCustomerEmail($customer) ?? '';
        }

        if ($to === '') {
            return response()->json([
                'message' => 'No recipient email. Add a customer email or enter one manually.',
            ], 422);
        }

        try {
            $result = $this->documentEmailService->sendPaymentReceipt(
                $payment,
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

    /**
     * Seller owns the payment row; buyer may view/download when they are invoice.buyer_business_id.
     */
    protected function findVisiblePaymentOrFail(int $businessId, int $id): Payment
    {
        $payment = Payment::with(['recordedBy', 'payable'])->find($id);
        if (! $payment) {
            abort(404, 'Payment not found');
        }

        if ((int) $payment->business_id === $businessId) {
            return $payment;
        }

        $payable = $payment->payable;
        if (
            $payment->payable_type === 'invoice'
            && $payable instanceof Invoice
            && $payable->buyer_business_id
            && (int) $payable->buyer_business_id === $businessId
        ) {
            return $payment;
        }

        abort(404, 'Payment not found');
    }
}
