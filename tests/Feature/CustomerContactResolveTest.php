<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerContactResolveTest extends TestCase
{
    use RefreshDatabase;

    protected Business $business;

    protected User $user;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);

        $this->user = User::factory()->create(['is_active' => true]);
        $this->token = $this->user->createToken('admin')->plainTextToken;

        $this->business = Business::factory()->create([
            'owner_id' => $this->user->id,
            'status' => 'active',
        ]);
        $this->user->business_id = $this->business->id;
        $this->user->save();

        Role::create([
            'business_id' => $this->business->id,
            'name' => 'Admin',
            'slug' => 'admin',
            'permissions' => ['sales.view' => true, 'customers.view' => true],
        ]);
    }

    public function test_resolve_creates_customer_from_email(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/v1/customers/resolve', [
                'name' => 'Jane Doe',
                'email' => 'jane@example.com',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.email', 'jane@example.com')
            ->assertJsonPath('data.name', 'Jane Doe')
            ->assertJsonPath('data.phone', null);

        $this->assertDatabaseHas('customers', [
            'business_id' => $this->business->id,
            'email' => 'jane@example.com',
        ]);
    }

    public function test_resolve_merges_email_onto_existing_customer(): void
    {
        $customer = Customer::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Jane',
            'email' => null,
            'phone' => '0700000001',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/v1/customers/resolve', [
                'customer_id' => $customer->id,
                'email' => 'jane@example.com',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.id', $customer->id)
            ->assertJsonPath('data.email', 'jane@example.com');
    }

    public function test_resolve_finds_customer_by_email(): void
    {
        $customer = Customer::factory()->create([
            'business_id' => $this->business->id,
            'email' => 'repeat@example.com',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/v1/customers/resolve', [
                'email' => 'repeat@example.com',
                'name' => 'Updated Name',
            ]);

        $response->assertOk()->assertJsonPath('data.id', $customer->id);
    }
}
