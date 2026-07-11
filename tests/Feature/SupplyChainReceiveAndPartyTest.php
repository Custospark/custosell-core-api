<?php

namespace Tests\Feature;

use App\Models\{Business, Invoice, JournalEntry, JournalEntryLine, Product, PurchaseOrderItem, User};
use App\Services\SupplierInvoiceAccountingService;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsAccounting;
use Tests\TestCase;

class SupplyChainReceiveAndPartyTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAccounting;

    protected User $sellerOwner;

    protected User $buyerOwner;

    protected Business $seller;

    protected Business $buyer;

    protected string $sellerToken;

    protected string $buyerToken;

    protected Product $listedProduct;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);

        $this->sellerOwner = User::factory()->create(['is_active' => true]);
        $this->seller = Business::factory()->create([
            'owner_id' => $this->sellerOwner->id,
            'status' => 'active',
            'is_open_for_supply' => true,
            'supply_headline' => 'Wholesale groceries',
            'name' => 'Seller Co Supply',
        ]);
        $this->sellerOwner->business_id = $this->seller->id;
        $this->sellerOwner->save();
        $this->sellerToken = $this->sellerOwner->createToken('seller')->plainTextToken;

        $this->buyerOwner = User::factory()->create(['is_active' => true]);
        $this->buyer = Business::factory()->create([
            'owner_id' => $this->buyerOwner->id,
            'status' => 'active',
            'name' => 'Buyer Retail Ltd',
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
    }

    protected function authJson(string $token, string $method, string $uri, array $data = [])
    {
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

    public function test_buyer_invoice_payload_names_supplier_not_buyer(): void
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

        $invoiceId = (int) Invoice::query()->where('purchase_order_id', $poId)->value('id');

        $buyerShow = $this->asBuyer('GET', "/api/v1/invoices/{$invoiceId}")->assertStatus(200);
        $this->assertSame('received', $buyerShow->json('direction') ?? $buyerShow->json('data.direction'));
        $party = $buyerShow->json('party_name') ?? $buyerShow->json('data.party_name');
        $sellerName = $buyerShow->json('seller_business.name') ?? $buyerShow->json('data.seller_business.name');
        $this->assertSame($this->seller->name, $party);
        $this->assertSame($this->seller->name, $sellerName);
        $this->assertNotSame($this->buyer->name, $party);
    }

    public function test_buyer_can_create_local_product_when_receiving_po(): void
    {
        $create = $this->asBuyer('POST', '/api/v1/purchase-orders', [
            'seller_business_id' => $this->seller->id,
            'items' => [
                ['product_id' => $this->listedProduct->id, 'quantity' => 2],
            ],
        ]);
        $poId = $create->json('id');
        $this->asBuyer('POST', "/api/v1/purchase-orders/{$poId}/submit")->assertStatus(200);
        $this->asSeller('POST', "/api/v1/purchase-orders/{$poId}/accept")->assertStatus(200);
        $this->asSeller('POST', "/api/v1/purchase-orders/{$poId}/fulfill")->assertStatus(200);

        $lineId = (int) PurchaseOrderItem::query()
            ->where('purchase_order_id', $poId)
            ->value('id');

        $before = Product::query()->where('business_id', $this->buyer->id)->count();

        $this->asBuyer('POST', "/api/v1/purchase-orders/{$poId}/receive", [
            'items' => [
                ['id' => $lineId, 'create_product' => true],
            ],
        ])->assertStatus(200);

        $this->assertSame($before + 1, Product::query()->where('business_id', $this->buyer->id)->count());
        $created = Product::query()
            ->where('business_id', $this->buyer->id)
            ->where('name', $this->listedProduct->name)
            ->first();
        $this->assertNotNull($created);
        $this->assertSame(2, (int) $created->stock_quantity);
        $this->assertDatabaseHas('purchase_orders', [
            'id' => $poId,
            'status' => 'received',
        ]);
    }

    public function test_shared_po_invoice_posts_seller_ar_and_buyer_ap(): void
    {
        $this->seedAccountingForBusiness($this->seller);
        $this->seedAccountingForBusiness($this->buyer);

        $create = $this->asBuyer('POST', '/api/v1/purchase-orders', [
            'seller_business_id' => $this->seller->id,
            'items' => [
                ['product_id' => $this->listedProduct->id, 'quantity' => 2],
            ],
        ]);
        $poId = $create->json('id');
        $this->asBuyer('POST', "/api/v1/purchase-orders/{$poId}/submit")->assertStatus(200);
        $this->asSeller('POST', "/api/v1/purchase-orders/{$poId}/accept")->assertStatus(200);

        $invoice = Invoice::query()->where('purchase_order_id', $poId)->first();
        $this->assertNotNull($invoice);
        $total = round((float) $invoice->total_amount, 2);

        $sellerAr = JournalEntry::query()
            ->where('business_id', $this->seller->id)
            ->where('reference_type', 'invoice')
            ->where('reference_id', $invoice->id)
            ->first();
        $this->assertNotNull($sellerAr, 'Seller AR invoice JE expected');

        $buyerAp = JournalEntry::query()
            ->where('business_id', $this->buyer->id)
            ->where('reference_type', SupplierInvoiceAccountingService::REF_INVOICE)
            ->where('reference_id', $invoice->id)
            ->first();
        $this->assertNotNull($buyerAp, 'Buyer supplier_invoice AP JE expected');

        $apCredit = JournalEntryLine::query()
            ->where('entry_id', $buyerAp->id)
            ->whereHas('chartOfAccount', fn ($q) => $q->where('code', '2101'))
            ->sum('credit_amount');
        $this->assertEqualsWithDelta($total, (float) $apCredit, 0.02);

        $invDebit = JournalEntryLine::query()
            ->where('entry_id', $buyerAp->id)
            ->whereHas('chartOfAccount', fn ($q) => $q->where('code', '1104'))
            ->sum('debit_amount');
        $this->assertEqualsWithDelta((float) $invoice->subtotal, (float) $invDebit, 0.02);

        $payAmount = min(10.0, $total);
        $this->asSeller('POST', "/api/v1/invoices/{$invoice->id}/payment", [
            'amount' => $payAmount,
            'payment_method' => 'cash',
        ])->assertStatus(200);

        $paymentId = (int) \App\Models\Payment::query()
            ->where('payable_type', 'invoice')
            ->where('payable_id', $invoice->id)
            ->value('id');
        $this->assertGreaterThan(0, $paymentId);

        $sellerPay = JournalEntry::query()
            ->where('business_id', $this->seller->id)
            ->where('reference_type', 'invoice_payment')
            ->where('reference_id', $paymentId)
            ->first();
        $this->assertNotNull($sellerPay, 'Seller invoice_payment JE expected');

        $buyerSettle = JournalEntry::query()
            ->where('business_id', $this->buyer->id)
            ->where('reference_type', SupplierInvoiceAccountingService::REF_PAYMENT)
            ->where('reference_id', $paymentId)
            ->first();
        $this->assertNotNull($buyerSettle, 'Buyer supplier_invoice_payment JE expected');

        $apDebit = JournalEntryLine::query()
            ->where('entry_id', $buyerSettle->id)
            ->whereHas('chartOfAccount', fn ($q) => $q->where('code', '2101'))
            ->sum('debit_amount');
        $this->assertEqualsWithDelta($payAmount, (float) $apDebit, 0.02);
    }
}
