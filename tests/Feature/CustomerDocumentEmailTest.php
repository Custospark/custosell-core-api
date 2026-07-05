<?php

namespace Tests\Feature;

use App\Mail\CustomerDocumentEmail;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\SeedsAccounting;
use Tests\TestCase;

class CustomerDocumentEmailTest extends TestCase
{
    use RefreshDatabase;
    use SeedsAccounting;

    protected Business $business;

    protected User $user;

    protected string $token;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);

        $this->user = User::factory()->create(['is_active' => true]);
        $this->token = $this->user->createToken('admin')->plainTextToken;

        $this->business = Business::factory()->create([
            'owner_id' => $this->user->id,
            'currency' => 'UGX',
            'status' => 'active',
            'business_email' => 'shop@example.com',
        ]);
        $this->user->business_id = $this->business->id;
        $this->user->save();

        Role::create([
            'business_id' => $this->business->id,
            'name' => 'Admin',
            'slug' => 'admin',
            'permissions' => [
                'invoices.view' => true,
                'invoices.create' => true,
                'sales.view' => true,
            ],
        ]);

        $this->seedAccountingForBusiness($this->business);

        $this->customer = Customer::factory()->create([
            'business_id' => $this->business->id,
            'email' => 'customer@example.com',
        ]);
    }

    public function test_email_draft_invoice_to_customer(): void
    {
        Mail::fake();

        $product = Product::factory()->create(['business_id' => $this->business->id]);
        $invoice = Invoice::create([
            'business_id' => $this->business->id,
            'invoice_number' => 'INV-EMAIL-1',
            'customer_id' => $this->customer->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'status' => 'draft',
            'subtotal' => 1000,
            'tax_total' => 0,
            'total_amount' => 1000,
            'amount_paid' => 0,
            'created_by' => $this->user->id,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'description' => 'Test item',
            'quantity' => 1,
            'unit_price' => 1000,
            'subtotal' => 1000,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/invoices/{$invoice->id}/email", [
                'message' => 'Please review and pay at your convenience.',
            ]);

        $response->assertOk()
            ->assertJsonPath('sent_to', 'customer@example.com')
            ->assertJsonPath('document_type', 'invoice')
            ->assertJsonPath('document_ref', 'INV-EMAIL-1')
            ->assertJsonPath('email_sent_count', 1);

        Mail::assertSent(CustomerDocumentEmail::class, function (CustomerDocumentEmail $mail): bool {
            return $mail->hasTo('customer@example.com')
                && str_contains($mail->subjectLine, 'INV-EMAIL-1')
                && count($mail->fileAttachments) === 1
                && $mail->fileAttachments[0]['mime'] === 'application/pdf';
        });
    }

    public function test_email_invoice_requires_recipient_when_customer_has_no_email(): void
    {
        $customer = Customer::factory()->create([
            'business_id' => $this->business->id,
            'email' => null,
        ]);

        $invoice = Invoice::create([
            'business_id' => $this->business->id,
            'invoice_number' => 'INV-NO-EMAIL',
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'status' => 'draft',
            'subtotal' => 500,
            'tax_total' => 0,
            'total_amount' => 500,
            'amount_paid' => 0,
            'created_by' => $this->user->id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/invoices/{$invoice->id}/email");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'No recipient email. Add a customer email or enter one manually.');
    }

    public function test_email_payment_receipt_with_manual_recipient(): void
    {
        Mail::fake();

        $sale = Sale::create([
            'business_id' => $this->business->id,
            'user_id' => $this->user->id,
            'customer_id' => $this->customer->id,
            'receipt_number' => 'SALE-EMAIL-1',
            'subtotal' => 2000,
            'tax_total' => 0,
            'discount_amount' => 0,
            'total_amount' => 2000,
            'amount_paid' => 2000,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'sale_date' => now(),
        ]);

        $payment = Payment::create([
            'business_id' => $this->business->id,
            'payable_type' => 'sale',
            'payable_id' => $sale->id,
            'receipt_number' => 'RCP-EMAIL-1',
            'amount' => 2000,
            'payment_method' => 'cash',
            'balance_after' => 0,
            'recorded_by' => $this->user->id,
            'paid_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/payments/{$payment->id}/email", [
                'to' => 'payer@example.com',
            ]);

        $response->assertOk()
            ->assertJsonPath('sent_to', 'payer@example.com')
            ->assertJsonPath('document_type', 'payment_receipt')
            ->assertJsonPath('document_ref', 'RCP-EMAIL-1');

        Mail::assertSent(CustomerDocumentEmail::class, function (CustomerDocumentEmail $mail): bool {
            return $mail->hasTo('payer@example.com')
                && str_contains($mail->subjectLine, 'RCP-EMAIL-1');
        });
    }

    public function test_invoice_email_sent_count_increments_on_resend(): void
    {
        Mail::fake();

        $invoice = Invoice::create([
            'business_id' => $this->business->id,
            'invoice_number' => 'INV-RESEND',
            'customer_id' => $this->customer->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'status' => 'draft',
            'subtotal' => 1000,
            'tax_total' => 0,
            'total_amount' => 1000,
            'amount_paid' => 0,
            'created_by' => $this->user->id,
        ]);

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/invoices/{$invoice->id}/email")
            ->assertJsonPath('email_sent_count', 1);

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/invoices/{$invoice->id}/email")
            ->assertJsonPath('email_sent_count', 2);

        $this->assertSame(2, (int) $invoice->fresh()->email_sent_count);
    }
}
