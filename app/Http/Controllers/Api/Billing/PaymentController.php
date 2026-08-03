<?php

namespace App\Http\Controllers\Api\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\InitiatePaymentRequest;
use App\Http\Resources\Billing\PaymentCollection;
use App\Http\Resources\Billing\PaymentResource;
use App\Services\Billing\BillingHistoryService;
use App\Services\Contracts\PaymentServiceInterface;
use App\Services\Contracts\SubscriptionServiceInterface;
use App\Services\Payment\GatewayService;
use App\Services\Payment\PaymentReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        protected PaymentServiceInterface $paymentService,
        protected SubscriptionServiceInterface $subscriptionService,
        protected GatewayService $gatewayService,
        protected PaymentReceiptService $receiptService,
        protected BillingHistoryService $historyService,
    ) {}

    public function index(Request $request): PaymentCollection
    {
        return new PaymentCollection(
            $this->paymentService->getByBusiness($request->user()->business_id)
        );
    }

    public function show(Request $request, int $id): PaymentResource
    {
        $payment = $this->paymentService->getById($id);

        if (!$payment) {
            abort(404, 'Payment not found.');
        }

        if ($payment->business_id !== $request->user()->business_id) {
            abort(403, 'You do not have access to this payment.');
        }

        return new PaymentResource($payment);
    }

    public function confirm(int $id, Request $request): JsonResponse
    {
        $payment = $this->paymentService->getById($id);

        if (!$payment) {
            return response()->json(['success' => false, 'message' => 'Payment not found.'], 404);
        }

        if ($payment->business_id !== $request->user()->business_id) {
            return response()->json(['success' => false, 'message' => 'Access denied.'], 403);
        }

        $result = $this->gatewayService->confirmPayment($id);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function downloadReceipt(int $id, Request $request)
    {
        $payment = $this->paymentService->getById($id);

        if (!$payment) {
            abort(404, 'Payment not found.');
        }

        if ($payment->business_id !== $request->user()->business_id) {
            abort(403, 'You do not have access to this payment.');
        }

        return $this->receiptService->download($payment);
    }

    public function emailReceipt(int $id, Request $request): JsonResponse
    {
        $payment = $this->paymentService->getById($id);

        if (!$payment) {
            return response()->json(['success' => false, 'message' => 'Payment not found.'], 404);
        }

        if ($payment->business_id !== $request->user()->business_id) {
            return response()->json(['success' => false, 'message' => 'Access denied.'], 403);
        }

        $sent = $this->receiptService->email($payment);

        if (!$sent) {
            return response()->json([
                'success' => false,
                'message' => 'Receipt could not be emailed. No recipient email on file or mail failed.',
            ], 422);
        }

        return response()->json(['success' => true, 'message' => 'Receipt sent.']);
    }

    public function history(Request $request): JsonResponse
    {
        $subscription = $this->subscriptionService->getByBusiness($request->user()->business_id);

        if (!$subscription) {
            return response()->json(['success' => false, 'message' => 'No active subscription found.'], 404);
        }

        return response()->json([
            'data' => $this->historyService->feed($subscription),
        ]);
    }

    public function initiateGateway(InitiatePaymentRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $subscription = $this->subscriptionService->getByBusiness($request->user()->business_id);

        if (!$subscription) {
            return response()->json([
                'message' => 'No active subscription found. Please subscribe before making a payment.',
            ], 404);
        }

        try {
            $result = $this->gatewayService->initiatePayment(
                $subscription,
                $validated['gateway_name'],
                [
                    'amount' => $validated['amount'],
                    'currency' => strtoupper($validated['currency']),
                    'payment_type' => $validated['payment_type'],
                    'billing_cycle' => $validated['billing_cycle'] ?? null,
                    'email' => $request->user()->email,
                    'customer_name' => $request->user()->name,
                    'phone_number' => $validated['phone'] ?? $request->user()->phone ?? '',
                    'metadata' => $validated['metadata'] ?? null,
                    'topup_months' => $validated['topup_months'] ?? null,
                    'idempotency_key' => $validated['idempotency_key'] ?? null,
                ]
            );

            return response()->json($result, 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to initiate payment with ' . $validated['gateway_name'] . ': ' . $e->getMessage(),
            ], 502);
        }
    }
}
