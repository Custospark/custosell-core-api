<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shopping account (storefront_buyer) contract - Discover-only buyers.
 * Kept separate from StorefrontTest (which is over the 500-line cap).
 */
class StorefrontBuyerAccountTypeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_storefront_buyer_register_preserves_account_type(): void
    {
        $res = $this->postJson('/api/v1/auth/register', [
            'name' => 'Discover Shopper',
            'email' => 'shopper@example.com',
            'password' => 'secret12',
            'password_confirmation' => 'secret12',
            'phone' => '+256700111222',
            'account_type' => 'storefront_buyer',
        ]);

        $res->assertCreated();
        $userPath = $res->json('user.data') ? 'user.data' : 'user';
        $this->assertSame('storefront_buyer', $res->json("$userPath.account_type"));
        $this->assertNull($res->json("$userPath.business_id"));
        $this->assertSame([], $res->json("$userPath.modules") ?? []);
        // No plans to subscribe to - shopping accounts buy from shops, not Custosell.
        $this->assertSame([], $res->json("$userPath.active_plans") ?? []);
    }

    public function test_storefront_buyer_has_no_workspace(): void
    {
        $res = $this->postJson('/api/v1/auth/register', [
            'name' => 'Discover Shopper',
            'email' => 'shopper@example.com',
            'password' => 'secret12',
            'password_confirmation' => 'secret12',
            'account_type' => 'storefront_buyer',
        ]);

        $res->assertCreated();
        $user = User::query()->where('email', 'shopper@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('storefront_buyer', $user->account_type);
        $this->assertNull($user->business_id);
        $this->assertNull($user->role_id);
        $this->assertSame([], $user->modules ?? []);

        // A shopping account is a buyer - can't manage payments or see business modules.
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'shopper@example.com',
            'password' => 'secret12',
        ]);
        $login->assertOk();
    }
}
