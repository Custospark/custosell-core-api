<?php

namespace Tests\Feature;

use App\Models\{Business, Product, Role, User};
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessExportTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $staff;
    protected Business $business;
    protected string $adminToken;
    protected string $staffToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->adminToken = $this->admin->createToken('admin')->plainTextToken;

        $this->business = Business::factory()->create([
            'owner_id' => $this->admin->id,
            'currency' => 'UGX',
            'status' => 'active',
        ]);
        $this->admin->business_id = $this->business->id;
        $this->admin->save();

        $adminRole = Role::create([
            'business_id' => $this->business->id,
            'name' => 'Admin',
            'slug' => 'admin',
            'permissions' => ['settings.view' => true, 'settings.edit' => true],
        ]);
        $this->admin->role_id = $adminRole->id;
        $this->admin->save();

        $this->staff = User::factory()->create([
            'business_id' => $this->business->id,
            'is_active' => true,
        ]);
        $this->staffToken = $this->staff->createToken('staff')->plainTextToken;

        Product::factory()->count(3)->create(['business_id' => $this->business->id]);
    }

    public function test_export_json_returns_200_with_structure(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson('/api/v1/businesses/export?format=json');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'exported_at',
                    'business',
                    'products',
                    'customers',
                    'sales',
                    'users',
                    'roles',
                ],
            ]);
    }

    public function test_export_returns_products_count(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson('/api/v1/businesses/export?format=json');

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data.products'));
    }

    public function test_export_without_token_returns_401(): void
    {
        $response = $this->getJson('/api/v1/businesses/export');

        $response->assertStatus(401);
    }

    public function test_export_by_staff_returns_403(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->staffToken")
            ->getJson('/api/v1/businesses/export');

        $response->assertStatus(403);
    }

    public function test_export_csv_format_returns_200(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->get('/api/v1/businesses/export?format=csv');

        $response->assertStatus(200);
    }

    public function test_export_invalid_format_returns_422(): void
    {
        $response = $this->withHeader('Authorization', "Bearer $this->adminToken")
            ->getJson('/api/v1/businesses/export?format=pdf');

        $response->assertStatus(422);
    }
}
