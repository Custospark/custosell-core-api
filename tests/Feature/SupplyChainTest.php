<?php

namespace Tests\Feature;

use App\Models\{Business, Product, User};
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplyChainTest extends TestCase
{
    use RefreshDatabase;

    protected User $sellerOwner;

    protected User $buyerOwner;

    protected Business $seller;

    protected Business $buyer;

    protected string $sellerToken;

    protected string $buyerToken;

    protected Product $listedProduct;

    protected Product $unlistedProduct;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);

        $this->sellerOwner = User::factory()->create(['is_active' => true]);
        $this->seller = Business::factory()->create([
            'owner_id' => $this->sellerOwner->id,
            'status' => 'active',
            'is_open_for_supply' => true,
            'supply_headline' => 'Wholesale groceries, next-day delivery',
        ]);
        $this->sellerOwner->business_id = $this->seller->id;
        $this->sellerOwner->save();
        $this->sellerToken = $this->sellerOwner->createToken('seller')->plainTextToken;

        $this->buyerOwner = User::factory()->create(['is_active' => true]);
        $this->buyer = Business::factory()->create([
            'owner_id' => $this->buyerOwner->id,
            'status' => 'active',
        ]);
        $this->buyerOwner->business_id = $this->buyer->id;
        $this->buyerOwner->save();
        $this->buyerToken = $this->buyerOwner->createToken('buyer')->plainTextToken;

        $this->listedProduct = Product::factory()->create([
            'business_id' => $this->seller->id,
            'name' => 'Listed Rice 50kg',
            'stock_quantity' => 100,
            'unit_price' => 1000,
            'is_active' => true,
            'listed_for_supply' => true,
            'supply_price' => 900,
            'supply_min_qty' => 1,
            'listed_at' => now(),
        ]);

        $this->unlistedProduct = Product::factory()->create([
            'business_id' => $this->seller->id,
            'name' => 'Unlisted Sugar 50kg',
            'stock_quantity' => 50,
            'is_active' => true,
            'listed_for_supply' => false,
        ]);
    }

    protected function authJson(string $token, string $method, string $uri, array $data = [])
    {
        // Sanctum's guard caches the resolved user for the lifetime of the request
        // guard instance, which otherwise persists across simulated requests within
        // a single test. Forget it so each call re-authenticates off its own token.
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}")->json($method, $uri, $data);
    }

    protected function asBuyer(string $method, string $uri, array $data = [])
    {
        return $this->authJson($this->buyerToken, $method, $uri, $data);
    }

    protected function asSeller(string $method, string $uri, array $data = [])
    {
        return $this->authJson($this->sellerToken, $method, $uri, $data);
    }

    public function test_listed_product_visible_and_unlisted_hidden_on_marketplace(): void
    {
        $response = $this->asBuyer('GET', "/api/v1/marketplace/businesses/{$this->seller->id}/products");

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($this->listedProduct->id, $ids);
        $this->assertNotContains($this->unlistedProduct->id, $ids);
    }

    public function test_closed_supply_business_hidden_from_marketplace(): void
    {
        $closedOwner = User::factory()->create(['is_active' => true]);
        $closed = Business::factory()->create([
            'owner_id' => $closedOwner->id,
            'status' => 'active',
            'is_open_for_supply' => false,
        ]);
        Product::factory()->create([
            'business_id' => $closed->id,
            'is_active' => true,
            'listed_for_supply' => true,
            'supply_price' => 500,
        ]);

        $listResponse = $this->asBuyer('GET', '/api/v1/marketplace/businesses');
        $listResponse->assertStatus(200);
        $businessIds = collect($listResponse->json('data'))->pluck('id')->all();

        $this->assertContains($this->seller->id, $businessIds);
        $this->assertNotContains($closed->id, $businessIds);

        $productsResponse = $this->asBuyer('GET', "/api/v1/marketplace/businesses/{$closed->id}/products");
        $productsResponse->assertStatus(200)->assertJsonCount(0, 'data');
    }

    public function test_full_happy_path_submit_accept_fulfill_receive(): void
    {
        $localProduct = Product::factory()->create([
            'business_id' => $this->buyer->id,
            'name' => 'Rice 50kg (local)',
            'stock_quantity' => 0,
        ]);

        $create = $this->asBuyer('POST', '/api/v1/purchase-orders', [
            'seller_business_id' => $this->seller->id,
            'items' => [
                ['product_id' => $this->listedProduct->id, 'quantity' => 10],
            ],
        ]);
        $create->assertStatus(201)->assertJsonPath('status', 'draft');
        $poId = $create->json('id');
        $itemId = $create->json('items.0.id');

        $this->assertDatabaseHas('purchase_orders', [
            'id' => $poId,
            'buyer_business_id' => $this->buyer->id,
            'seller_business_id' => $this->seller->id,
            'status' => 'draft',
        ]);

        $this->asBuyer('POST', "/api/v1/purchase-orders/{$poId}/submit")
            ->assertStatus(200)
            ->assertJsonPath('status', 'submitted');

        $this->asSeller('GET', '/api/v1/purchase-orders/incoming')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->asSeller('POST', "/api/v1/purchase-orders/{$poId}/accept")
            ->assertStatus(200)
            ->assertJsonPath('status', 'accepted');

        $this->assertDatabaseHas('invoices', [
            'purchase_order_id' => $poId,
            'business_id' => $this->seller->id,
            'buyer_business_id' => $this->buyer->id,
        ]);

        $buyerInvoices = $this->asBuyer('GET', '/api/v1/invoices');
        $buyerInvoices->assertStatus(200);
        $poInvoice = collect($buyerInvoices->json('data'))->firstWhere('purchase_order_id', $poId);
        $this->assertNotNull($poInvoice);
        $this->assertSame('received', $poInvoice['direction']);
        $this->assertNotSame('draft', $poInvoice['status']);

        $this->asSeller('POST', "/api/v1/purchase-orders/{$poId}/fulfill")
            ->assertStatus(200)
            ->assertJsonPath('status', 'fulfilled');

        $this->assertSame(90, $this->listedProduct->fresh()->stock_quantity);
        $this->assertDatabaseHas('stock_movements', [
            'business_id' => $this->seller->id,
            'product_id' => $this->listedProduct->id,
            'type' => 'sale',
            'quantity_change' => -10,
        ]);

        $receive = $this->asBuyer('POST', "/api/v1/purchase-orders/{$poId}/receive", [
            'items' => [
                ['id' => $itemId, 'product_id' => $localProduct->id],
            ],
        ]);
        $receive->assertStatus(200)->assertJsonPath('status', 'received');

        $this->assertSame(10, $localProduct->fresh()->stock_quantity);
        $this->assertDatabaseHas('stock_movements', [
            'business_id' => $this->buyer->id,
            'product_id' => $localProduct->id,
            'type' => 'purchase',
            'quantity_change' => 10,
        ]);
    }

    public function test_cannot_fulfill_without_sufficient_stock(): void
    {
        $create = $this->asBuyer('POST', '/api/v1/purchase-orders', [
            'seller_business_id' => $this->seller->id,
            'items' => [
                ['product_id' => $this->listedProduct->id, 'quantity' => 500],
            ],
        ]);
        $poId = $create->json('id');

        $this->asBuyer('POST', "/api/v1/purchase-orders/{$poId}/submit")->assertStatus(200);
        $this->asSeller('POST', "/api/v1/purchase-orders/{$poId}/accept")->assertStatus(200);

        $this->asSeller('POST', "/api/v1/purchase-orders/{$poId}/fulfill")
            ->assertStatus(422);

        $this->assertSame(100, $this->listedProduct->fresh()->stock_quantity);
        $this->assertDatabaseHas('purchase_orders', ['id' => $poId, 'status' => 'accepted']);
        $this->assertDatabaseMissing('stock_movements', [
            'product_id' => $this->listedProduct->id,
            'type' => 'sale',
        ]);
    }

    public function test_buyer_cannot_see_other_buyers_purchase_order(): void
    {
        $create = $this->asBuyer('POST', '/api/v1/purchase-orders', [
            'seller_business_id' => $this->seller->id,
            'items' => [
                ['product_id' => $this->listedProduct->id, 'quantity' => 1],
            ],
        ]);
        $poId = $create->json('id');

        $otherBuyerOwner = User::factory()->create(['is_active' => true]);
        $otherBuyer = Business::factory()->create([
            'owner_id' => $otherBuyerOwner->id,
            'status' => 'active',
        ]);
        $otherBuyerOwner->business_id = $otherBuyer->id;
        $otherBuyerOwner->save();
        $otherBuyerToken = $otherBuyerOwner->createToken('other')->plainTextToken;

        $this->authJson($otherBuyerToken, 'GET', "/api/v1/purchase-orders/{$poId}")
            ->assertStatus(404);

        $this->authJson($otherBuyerToken, 'GET', '/api/v1/purchase-orders')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        $this->authJson($otherBuyerToken, 'POST', "/api/v1/purchase-orders/{$poId}/submit")
            ->assertStatus(404);
    }

    public function test_cross_tenant_cannot_list_someone_elses_product_for_supply(): void
    {
        $response = $this->asBuyer('PATCH', "/api/v1/products/{$this->listedProduct->id}/supply-listing", [
            'listed_for_supply' => true,
            'supply_price' => 1200,
        ]);

        $response->assertStatus(404);

        $this->assertDatabaseHas('products', [
            'id' => $this->listedProduct->id,
            'supply_price' => 900,
        ]);
    }

    public function test_buyer_can_delete_draft_and_rejected_purchase_orders(): void
    {
        $draft = $this->asBuyer('POST', '/api/v1/purchase-orders', [
            'seller_business_id' => $this->seller->id,
            'items' => [
                ['product_id' => $this->listedProduct->id, 'quantity' => 1],
            ],
        ]);
        $draft->assertStatus(201);
        $draftId = $draft->json('id');

        $this->asBuyer('DELETE', "/api/v1/purchase-orders/{$draftId}")
            ->assertStatus(204);
        $this->assertDatabaseMissing('purchase_orders', ['id' => $draftId]);

        $create = $this->asBuyer('POST', '/api/v1/purchase-orders', [
            'seller_business_id' => $this->seller->id,
            'items' => [
                ['product_id' => $this->listedProduct->id, 'quantity' => 1],
            ],
        ]);
        $poId = $create->json('id');
        $this->asBuyer('POST', "/api/v1/purchase-orders/{$poId}/submit")->assertStatus(200);
        $this->asSeller('POST', "/api/v1/purchase-orders/{$poId}/reject", [
            'rejection_reason' => 'Out of stock this week',
        ])->assertStatus(200);

        $this->asBuyer('DELETE', "/api/v1/purchase-orders/{$poId}")
            ->assertStatus(204);
        $this->assertDatabaseMissing('purchase_orders', ['id' => $poId]);
    }

    public function test_cannot_delete_accepted_purchase_order(): void
    {
        $create = $this->asBuyer('POST', '/api/v1/purchase-orders', [
            'seller_business_id' => $this->seller->id,
            'items' => [
                ['product_id' => $this->listedProduct->id, 'quantity' => 1],
            ],
        ]);
        $poId = $create->json('id');
        $this->asBuyer('POST', "/api/v1/purchase-orders/{$poId}/submit")->assertStatus(200);
        $this->asSeller('POST', "/api/v1/purchase-orders/{$poId}/accept")->assertStatus(200);

        $this->asBuyer('DELETE', "/api/v1/purchase-orders/{$poId}")
            ->assertStatus(422);
        $this->assertDatabaseHas('purchase_orders', ['id' => $poId, 'status' => 'accepted']);
    }
}
