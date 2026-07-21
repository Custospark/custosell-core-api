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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function __construct(
        protected SubscriptionServiceInterface $subscriptionService,
        protected SubscriptionScheduledChangeServiceInterface $scheduledChangeService,
        protected SubscriptionProrationCalculator $prorationCalculator,
        protected PaymentQuoteService $paymentQuoteService,
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
        return response()->json(new SubscriptionResource($subscription), 201);
    }

    public function update(SubscriptionRequest $request, int $id): SubscriptionResource
    {
        $subscription = $this->subscriptionService->update($id, $request->validated());
        return new SubscriptionResource($subscription);
    }

    public function current(Request $request): SubscriptionResource
    {
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

        $subscription = $this->subscriptionService->subscribe(
            $request->user()->business_id,
            $validated['plan_id'],
            $validated['billing_cycle'] ?? 'monthly',
            $validated['referral_code'] ?? null
        );

        return response()->json(new SubscriptionResource($subscription), 201);
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
        return response()->json(new SubscriptionResource($reactivated));
    }

    public function upgrade(Request $request, int $id): JsonResponse
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
        $effective = $validated['effective'] ?? 'immediate';

        if ($effective === 'immediate') {
            $change = $this->scheduledChangeService->schedulePlanChange(
                $subscription->id, $toPlanId, 'upgrade'
            );
            $this->subscriptionService->update($subscription->id, ['plan_id' => $toPlanId]);
        } else {
            $change = $this->scheduledChangeService->schedulePlanChange(
                $subscription->id, $toPlanId, 'upgrade'
            );
        }

        $quote = $this->paymentQuoteService->getQuote($subscription, $toPlanId);

        return response()->json([
            'scheduled_change' => $change->toArray(),
            'proration' => $quote,
        ]);
    }

    public function downgrade(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'to_plan_id' => ['required', 'integer', 'exists:plans,id'],
        ]);

        $subscription = $this->subscriptionService->getById($id);
        if (!$subscription) {
            abort(404, 'Subscription not found');
        }

        if ($subscription->business_id !== $request->user()->business_id) {
            abort(403);
        }

        $toPlanId = (int) $validated['to_plan_id'];
        $change = $this->scheduledChangeService->schedulePlanChange(
            $subscription->id, $toPlanId, 'downgrade'
        );

        $quote = $this->paymentQuoteService->getQuote($subscription, $toPlanId);

        return response()->json([
            'scheduled_change' => $change->toArray(),
            'proration' => $quote,
        ]);
    }

    public function checkAccess(Request $request): JsonResponse
    {
        $hasAccess = $this->subscriptionService->hasAccess($request->user()->business_id);
        return response()->json(['has_access' => $hasAccess]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->subscriptionService->delete($id);
        return response()->json(null, 204);
    }
}
