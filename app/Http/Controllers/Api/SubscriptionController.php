<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubscriptionRequest;
use App\Http\Resources\SubscriptionCollection;
use App\Http\Resources\SubscriptionResource;
use App\Services\Billing\PaymentQuoteService;
use App\Services\Billing\SubscriptionProrationCalculator;
use App\Services\Contracts\SubscriptionServiceInterface;
use App\Services\Contracts\SubscriptionScheduledChangeServiceInterface;
use App\Services\Payment\GatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function __construct(
        protected SubscriptionServiceInterface $subscriptionService,
        protected SubscriptionScheduledChangeServiceInterface $scheduledChangeService,
        protected SubscriptionProrationCalculator $prorationCalculator,
        protected PaymentQuoteService $paymentQuoteService,
        protected GatewayService $gatewayService,
    ) {}

    public function index(): SubscriptionCollection
    {
        return new SubscriptionCollection($this->subscriptionService->getAll());
    }

    public function show(int $id): SubscriptionResource
    {
        $subscription = $this->subscriptionService->getById($id);
        if (!$subscription) {
            abort(404, 'Subscription not found');
        }
        return new SubscriptionResource($subscription);
    }

    public function store(SubscriptionRequest $request): JsonResponse
    {
        $subscription = $this->subscriptionService->create($request->validated());
        return response()->json(['data' => new SubscriptionResource($subscription)], 201);
    }

    public function update(SubscriptionRequest $request, int $id): JsonResponse
    {
        try {
            $subscription = $this->subscriptionService->update($id, $request->validated());
            return response()->json(['data' => new SubscriptionResource($subscription)]);
        } catch (\RuntimeException $e) {
            abort(404, 'Subscription not found');
        }
    }

    public function current(Request $request): SubscriptionResource
    {
        $this->scheduledChangeService->applyPendingChanges();

        $subscription = $this->subscriptionService->getByBusiness($request->user()->business_id);
        if (!$subscription) {
            abort(404, 'No active subscription found for this business.');
        }
        return new SubscriptionResource($subscription);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'billing_cycle' => ['sometimes', 'string', 'in:monthly,yearly'],
            'referral_code' => ['sometimes', 'string', 'max:64'],
        ]);

        try {
            $subscription = $this->subscriptionService->subscribe(
                $request->user()->business_id,
                $validated['plan_id'],
                $validated['billing_cycle'] ?? 'monthly',
                $validated['referral_code'] ?? null
            );

            return response()->json(['data' => new SubscriptionResource($subscription)], 201);
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }
    }

    public function cancelPlan(Request $request, int $id): JsonResponse
    {
        $immediate = $request->boolean('immediate', false);

        if ($immediate) {
            $this->subscriptionService->cancelImmediately($id);
            return response()->json(['message' => 'Subscription has been cancelled immediately.']);
        }

        $this->subscriptionService->cancel($id, false);
        return response()->json(['message' => 'Subscription will be cancelled at the end of the billing period.']);
    }

    public function reactivate(Request $request, int $id): JsonResponse
    {
        $subscription = $this->subscriptionService->getById($id);

        if (!$subscription) {
            abort(404, 'Subscription not found');
        }

        if ($subscription->business_id !== $request->user()->business_id) {
            abort(403, 'You can only reactivate your own subscription.');
        }

        $reactivated = $this->subscriptionService->reactivate($subscription);
        return response()->json(['data' => new SubscriptionResource($reactivated)]);
    }

    public function upgrade(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'to_plan_id' => ['required', 'integer', 'exists:plans,id'],
            'effective' => ['sometimes', 'string', 'in:immediate,end_of_period'],
            'billing_cycle' => ['sometimes', 'string', 'in:monthly,yearly'],
        ]);

        $subscription = $this->subscriptionService->getById($id);
        if (!$subscription) {
            abort(404, 'Subscription not found');
        }

        if ($subscription->business_id !== $request->user()->business_id) {
            abort(403);
        }

        $toPlanId = (int) $validated['to_plan_id'];
        $effective = $validated['effective'] ?? 'immediate';
        $billingCycle = $validated['billing_cycle'] ?? null;

        $quote = $this->paymentQuoteService->getQuote($subscription, $toPlanId, $billingCycle);

        $this->assertUpgradeAllowed(
            $subscription->billing_cycle,
            $billingCycle,
            $quote['proration'] ?? [],
        );

        if ($effective === 'immediate') {
            $prorationDue = $quote['proration']['proration_due'] ?? 0;
            if ($prorationDue <= 0) {
                $this->gatewayService->processZeroCostUpgrade($subscription, $toPlanId, $billingCycle);
            } else {
                $meta = $subscription->metadata ?? [];
                $meta['pending_upgrade_amount_usd'] = (float) ($quote['proration']['proration_due_usd'] ?? $prorationDue);
                $meta['pending_upgrade_to_plan_id'] = $toPlanId;
                if ($billingCycle) {
                    $meta['pending_upgrade_billing_cycle'] = $billingCycle;
                }
                $subscription->update(['metadata' => $meta]);
            }
        } else {
            $this->scheduledChangeService->schedulePlanChange(
                $subscription->id, $toPlanId, 'upgrade'
            );
        }

        return response()->json([
            'proration' => $quote,
        ]);
    }

    public function downgrade(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'to_plan_id' => ['required', 'integer', 'exists:plans,id'],
            'effective' => ['sometimes', 'string', 'in:immediate,end_of_period'],
        ]);

        $subscription = $this->subscriptionService->getById($id);
        if (!$subscription) {
            abort(404, 'Subscription not found');
        }

        if ($subscription->business_id !== $request->user()->business_id) {
            abort(403);
        }

        $toPlanId = (int) $validated['to_plan_id'];
        $effective = $validated['effective'] ?? 'end_of_period';

        if ($effective === 'immediate') {
            $change = $this->scheduledChangeService->schedulePlanChange(
                $subscription->id, $toPlanId, 'downgrade'
            );
            $this->subscriptionService->update($subscription->id, ['plan_id' => $toPlanId]);
        } else {
            $change = $this->scheduledChangeService->schedulePlanChange(
                $subscription->id, $toPlanId, 'downgrade'
            );
        }

        $quote = $this->paymentQuoteService->getQuote($subscription, $toPlanId);

        return response()->json([
            'scheduled_change' => $change->toArray(),
            'proration' => $quote,
        ]);
    }

    public function prorationQuote(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'to_plan_id' => ['required', 'integer', 'exists:plans,id'],
            'billing_cycle' => ['sometimes', 'string', 'in:monthly,yearly'],
        ]);

        $subscription = $this->subscriptionService->getById($id);
        if (!$subscription) {
            abort(404, 'Subscription not found');
        }

        if ($subscription->business_id !== $request->user()->business_id) {
            abort(403);
        }

        $quote = $this->paymentQuoteService->getQuote(
            $subscription,
            (int) $validated['to_plan_id'],
            $validated['billing_cycle'] ?? null,
        );

        $this->assertUpgradeAllowed(
            $subscription->billing_cycle,
            $validated['billing_cycle'] ?? null,
            $quote['proration'] ?? [],
        );

        return response()->json(['data' => $quote]);
    }

    public function changeBillingCycle(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'billing_cycle' => ['required', 'string', 'in:monthly,yearly'],
            'effective' => ['sometimes', 'string', 'in:immediate,end_of_period'],
        ]);

        $subscription = $this->subscriptionService->getById($id);
        if (!$subscription) {
            abort(404, 'Subscription not found');
        }

        if ($subscription->business_id !== $request->user()->business_id) {
            abort(403);
        }

        try {
            $effective = $validated['effective'] ?? null;

            // Default effective based on direction
            if (!$effective) {
                $effective = $subscription->billing_cycle === 'monthly' && $validated['billing_cycle'] === 'yearly'
                    ? 'immediate'
                    : 'end_of_period';
            }

            // Monthly→Yearly immediate: require payment first (like upgrade proration)
            if ($effective === 'immediate' && $subscription->billing_cycle === 'monthly' && $validated['billing_cycle'] === 'yearly') {
                $quote = $this->paymentQuoteService->getQuote(
                    $subscription,
                    $subscription->plan_id,
                    $validated['billing_cycle'],
                );

                $meta = $subscription->metadata ?? [];
                $meta['pending_billing_cycle'] = $validated['billing_cycle'];
                $meta['pending_cycle_change_amount_usd'] = (float) ($quote['proration']['proration_due_usd'] ?? $quote['proration']['proration_due'] ?? 0);
                $subscription->update(['metadata' => $meta]);

                return response()->json([
                    'payment_required' => true,
                    'message' => 'Payment required to switch to yearly billing',
                    'proration' => $quote,
                ]);
            }

            // All other cases (end_of_period or yearly→monthly): use existing flow
            $updated = $this->subscriptionService->changeBillingCycle(
                $subscription,
                $validated['billing_cycle'],
                $effective,
            );

            return response()->json([
                'payment_required' => false,
                'message' => $effective === 'immediate'
                    ? 'Billing cycle changed successfully'
                    : 'Billing cycle change scheduled for end of current period',
                'data' => new SubscriptionResource($updated),
            ]);
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }
    }

    public function changes(int $id): JsonResponse
    {
        $this->scheduledChangeService->applyPendingChanges();

        $subscription = $this->subscriptionService->getById($id);
        if (!$subscription) {
            abort(404, 'Subscription not found');
        }

        $changes = $subscription->scheduledChanges()
            ->with(['fromPlan', 'toPlan', 'requestedBy'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['data' => $changes->toArray()]);
    }

    public function checkAccess(Request $request): JsonResponse
    {
        $hasAccess = $this->subscriptionService->hasAccess($request->user()->business_id);
        return response()->json(['has_access' => $hasAccess]);
    }

    public function cancelScheduledChange(Request $request, int $id): JsonResponse
    {
        $subscription = $this->subscriptionService->getById($id);
        if (!$subscription) {
            abort(404, 'Subscription not found');
        }

        if ($subscription->business_id !== $request->user()->business_id) {
            abort(403);
        }

        $this->scheduledChangeService->cancelPendingChange($subscription->id);

        return response()->json(['message' => 'Scheduled change cancelled']);
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $this->subscriptionService->delete($id);
            return response()->json(null, 204);
        } catch (\RuntimeException $e) {
            abort(404, 'Subscription not found');
        }
    }

    protected function assertUpgradeAllowed(?string $currentCycle, ?string $targetCycle, array $proration): void
    {
        if ($currentCycle !== 'yearly' || $targetCycle !== 'monthly') {
            return;
        }

        $credit = (float) ($proration['credit_usd'] ?? $proration['credit'] ?? 0);
        $charge = (float) ($proration['charge_usd'] ?? $proration['charge'] ?? 0);

        if ($credit > $charge) {
            abort(422, sprintf(
                'Your unused annual credit ($%.2f) exceeds the monthly upgrade price ($%.2f). Please choose the yearly option to continue upgrading, or wait until your annual period ends.',
                $credit,
                $charge,
            ));
        }
    }
}
