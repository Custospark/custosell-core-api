<?php

namespace Tests\Unit\Billing;

use App\Enums\Billing\DiscountType;
use App\Enums\Billing\ReferralCodeOwnerType;
use App\Enums\Billing\RewardType;
use App\Http\Requests\ReferralCodeRequest;
use App\Models\Plan;
use App\Models\ReferralCode;
use Database\Seeders\PlanSeeder;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ReferralCodeBusinessRewardRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    private function build(array $data, string $method = 'POST', ?int $id = null): ReferralCodeRequest
    {
        $uri = '/api/v1/platform/referral-codes' . ($id ? "/{$id}" : '');
        $request = ReferralCodeRequest::create($uri, $method, $data);

        if ($id) {
            $route = new Route($method, $uri, ['uses' => fn () => null]);
            $route->bind($request);
            $request->setRouteResolver(fn () => $route);
        }

        $request->setContainer($this->app);
        return $request;
    }

    private function validatorFor(array $data, string $method): ValidatorContract
    {
        $request = $this->build($data, $method, $data['_id'] ?? null);
        $validator = Validator::make($request->validationData(), $request->rules(), $request->messages());
        $request->withValidator($validator);
        return $validator;
    }

    private function assertFails(array $data, string $field): void
    {
        $hasId = !empty($data['_id']);
        try {
            $this->validatorFor($data, $hasId ? 'PUT' : 'POST')->validate();
            $this->fail('Expected validation to fail on [' . $field . '].');
        } catch (ValidationException $e) {
            $this->assertTrue(
                $e->validator->errors()->has($field),
                'Expected error on [' . $field . '] but got: ' . $e->validator->errors()->toJson()
            );
        }
    }

    private function assertPasses(array $data): void
    {
        $hasId = !empty($data['_id']);
        try {
            $this->validatorFor($data, $hasId ? 'PUT' : 'POST')->validate();
            $this->addToAssertionCount(1);
        } catch (ValidationException $e) {
            $this->fail('Expected validation to pass but got: ' . $e->validator->errors()->toJson());
        }
    }

    public function test_business_percentage_reward_capped_below_50(): void
    {
        $this->assertFails([
            'owner_type' => ReferralCodeOwnerType::BUSINESS->value,
            'discount_type' => DiscountType::PERCENTAGE->value,
            'discount_value' => 10,
            'reward_type' => RewardType::PERCENTAGE->value,
            'reward_value' => 50,
        ], 'reward_value');

        $this->assertPasses([
            'owner_type' => ReferralCodeOwnerType::BUSINESS->value,
            'discount_type' => DiscountType::PERCENTAGE->value,
            'discount_value' => 10,
            'reward_type' => RewardType::PERCENTAGE->value,
            'reward_value' => 15,
        ]);
    }

    public function test_business_flat_reward_capped_below_half_cheapest_plan_fee(): void
    {
        $minOnboarding = (float) Plan::where('is_active', true)
            ->where('onboarding_fee_usd', '>', 0)
            ->min('onboarding_fee_usd');

        $this->assertFails([
            'owner_type' => ReferralCodeOwnerType::BUSINESS->value,
            'discount_type' => DiscountType::PERCENTAGE->value,
            'discount_value' => 10,
            'reward_type' => RewardType::FLAT_AMOUNT->value,
            'reward_value' => $minOnboarding / 2,
        ], 'reward_value');

        $this->assertPasses([
            'owner_type' => ReferralCodeOwnerType::BUSINESS->value,
            'discount_type' => DiscountType::PERCENTAGE->value,
            'discount_value' => 10,
            'reward_type' => RewardType::FLAT_AMOUNT->value,
            'reward_value' => max(0.01, ($minOnboarding / 2) - 0.01),
        ]);
    }

    public function test_business_free_month_reward_disallowed(): void
    {
        $this->assertFails([
            'owner_type' => ReferralCodeOwnerType::BUSINESS->value,
            'discount_type' => DiscountType::PERCENTAGE->value,
            'discount_value' => 10,
            'reward_type' => RewardType::FREE_MONTH->value,
            'reward_value' => 0,
        ], 'reward_type');
    }

    public function test_business_status_toggle_on_legacy_free_month_code_not_blocked(): void
    {
        // A legacy business code carrying a free_month reward can still be
        // toggled (the guard fires only when reward fields are submitted); the
        // apply-time cap in markActive is what renormalizes it at payout.
        $code = ReferralCode::create([
            'owner_type' => ReferralCodeOwnerType::BUSINESS,
            'code' => 'LEGACYFRM',
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_value' => 10,
            'reward_type' => RewardType::FREE_MONTH,
            'reward_value' => 0,
            'is_active' => true,
        ]);

        $this->assertPasses([
            '_id' => $code->id,
            'is_active' => false,
        ]);
    }

    public function test_business_reward_guard_leaves_sales_rep_and_campaign_untouched(): void
    {
        $this->assertPasses([
            'owner_type' => ReferralCodeOwnerType::SALES_REP->value,
            'discount_type' => DiscountType::PERCENTAGE->value,
            'discount_value' => 20,
            'reward_type' => RewardType::PERCENTAGE->value,
            'reward_value' => 60,
        ]);

        $this->assertPasses([
            'owner_type' => ReferralCodeOwnerType::CAMPAIGN->value,
            'discount_type' => DiscountType::PERCENTAGE->value,
            'discount_value' => 20,
        ]);
    }
}