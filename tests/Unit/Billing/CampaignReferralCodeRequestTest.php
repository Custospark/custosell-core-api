<?php

namespace Tests\Unit\Billing;

use App\Enums\Billing\DiscountType;
use App\Enums\Billing\ReferralCodeOwnerType;
use App\Http\Requests\ReferralCodeRequest;
use App\Models\ReferralCode;
use Database\Seeders\PlanSeeder;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CampaignReferralCodeRequestTest extends TestCase
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

    public function test_campaign_percentage_discount_capped_at_30(): void
    {
        $this->assertFails([
            'owner_type' => ReferralCodeOwnerType::CAMPAIGN->value,
            'discount_type' => DiscountType::PERCENTAGE->value,
            'discount_value' => 31,
        ], 'discount_value');

        $this->assertPasses([
            'owner_type' => ReferralCodeOwnerType::CAMPAIGN->value,
            'discount_type' => DiscountType::PERCENTAGE->value,
            'discount_value' => 30,
        ]);
    }

    public function test_campaign_flat_discount_capped_below_half_cheapest_plan_fee(): void
    {
        $minOnboarding = \App\Http\Requests\ReferralCodeRequest::cheapestPlanFee();

        $this->assertFails([
            'owner_type' => ReferralCodeOwnerType::CAMPAIGN->value,
            'discount_type' => DiscountType::FLAT_AMOUNT->value,
            'discount_value' => $minOnboarding / 2,
        ], 'discount_value');

        $this->assertPasses([
            'owner_type' => ReferralCodeOwnerType::CAMPAIGN->value,
            'discount_type' => DiscountType::FLAT_AMOUNT->value,
            'discount_value' => ($minOnboarding / 2) - 0.01,
        ]);
    }

    public function test_campaign_duration_must_be_single_period(): void
    {
        $this->assertFails([
            'owner_type' => ReferralCodeOwnerType::CAMPAIGN->value,
            'discount_type' => DiscountType::PERCENTAGE->value,
            'discount_value' => 20,
            'discount_duration_months' => 3,
        ], 'discount_duration_months');
    }

    public function test_campaign_code_never_carries_reward(): void
    {
        $this->assertFails([
            'owner_type' => ReferralCodeOwnerType::CAMPAIGN->value,
            'discount_type' => DiscountType::PERCENTAGE->value,
            'discount_value' => 20,
            'reward_type' => 'flat_amount',
            'reward_value' => 10,
        ], 'reward_value');
    }

    public function test_sales_rep_duration_still_clamped(): void
    {
        $this->assertFails([
            'owner_type' => ReferralCodeOwnerType::SALES_REP->value,
            'discount_type' => DiscountType::PERCENTAGE->value,
            'discount_value' => 20,
            'discount_duration_months' => 6,
        ], 'discount_duration_months');
    }

    public function test_status_toggle_on_legacy_campaign_code_not_blocked(): void
    {
        $code = ReferralCode::create([
            'owner_type' => ReferralCodeOwnerType::CAMPAIGN,
            'code' => 'LEGACY35',
            'discount_type' => DiscountType::PERCENTAGE,
            'discount_value' => 35,
            'discount_duration_months' => 2,
            'is_active' => true,
        ]);

        $this->assertPasses([
            '_id' => $code->id,
            'is_active' => false,
        ]);
    }

    public function test_business_codes_unaffected_by_campaign_cap(): void
    {
        $this->assertPasses([
            'owner_type' => ReferralCodeOwnerType::BUSINESS->value,
            'discount_type' => DiscountType::FLAT_AMOUNT->value,
            'discount_value' => 1000,
            'discount_duration_months' => 6,
        ]);
    }
}