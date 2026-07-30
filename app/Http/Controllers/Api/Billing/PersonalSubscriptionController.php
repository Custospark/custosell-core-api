<?php

namespace App\Http\Controllers\Api\Billing;

use App\Http\Controllers\Controller;
use App\Models\BillingPayment;
use App\Models\PersonalModuleSubscription;
use App\Services\Billing\PersonalSubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PersonalSubscriptionController extends Controller
{
    public function __construct(
        protected PersonalSubscriptionService $personalSubscriptionService,
    ) {}

    public function availableModules(): JsonResponse
    {
        return response()->json([
            'modules' => collect(PersonalSubscriptionService::AVAILABLE_MODULES)->map(fn ($m, $slug) => [
                'slug' => $slug,
                'label' => $m['label'],
                'description' => $m['description'],
                'price_monthly_usd' => $m['price_monthly_usd'],
                'price_yearly_usd' => $m['price_yearly_usd'],
            ])->values(),
        ]);
    }

    public function mySubscriptions(Request $request): JsonResponse
    {
        $subscriptions = $this->personalSubscriptionService->activeModules($request->user());

        return response()->json([
            'subscriptions' => $subscriptions->map(fn ($s) => [
                'id' => $s->id,
                'module_slug' => $s->module_slug,
                'status' => $s->status,
                'billing_cycle' => $s->billing_cycle,
                'price_usd' => $s->price_usd,
                'current_period_end' => $s->current_period_end,
                'cancelled_at' => $s->cancelled_at,
            ]),
            'total_monthly_usd' => $this->personalSubscriptionService->totalMonthly($request->user()),
        ]);
    }

    public function subscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'module_slug' => ['required', 'string', Rule::in(array_keys(PersonalSubscriptionService::AVAILABLE_MODULES))],
            'billing_cycle' => ['sometimes', 'string', Rule::in(['monthly', 'yearly'])],
        ]);

        $moduleSlug = $validated['module_slug'];
        $billingCycle = $validated['billing_cycle'] ?? 'monthly';

        try {
            $subscription = $this->personalSubscriptionService->subscribe(
                $request->user(),
                $moduleSlug,
                $billingCycle,
            );

            return response()->json([
                'message' => "Subscribed to {$moduleSlug}.",
                'subscription' => $subscription,
            ], 201);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function initiatePayment(Request $request): JsonResponse
    {
        $user = $request->user();
        $activeSubscriptions = $this->personalSubscriptionService->activeModules($user);

        if ($activeSubscriptions->isEmpty()) {
            return response()->json(['message' => 'No active subscriptions to pay for.'], 422);
        }

        $total = (float) $activeSubscriptions->sum('price_usd');

        $payment = DB::transaction(function () use ($user, $total, $activeSubscriptions) {
            return BillingPayment::create([
                'user_id' => $user->id,
                'amount' => $total,
                'currency' => 'USD',
                'status' => 'pending',
                'payment_type' => 'subscription',
                'metadata' => [
                    'personal_modules' => $activeSubscriptions->pluck('module_slug')->toArray(),
                    'subscription_ids' => $activeSubscriptions->pluck('id')->toArray(),
                ],
            ]);
        });

        return response()->json([
            'message' => 'Payment initiated.',
            'payment' => [
                'id' => $payment->id,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'status' => $payment->status,
            ],
        ]);
    }

    public function cancel(int $id, Request $request): JsonResponse
    {
        $subscription = PersonalModuleSubscription::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->firstOrFail();

        if ($subscription->status !== 'active') {
            return response()->json(['message' => 'Subscription is not active.'], 422);
        }

        $this->personalSubscriptionService->cancel($subscription);

        return response()->json(['message' => 'Subscription cancelled.']);
    }
}
