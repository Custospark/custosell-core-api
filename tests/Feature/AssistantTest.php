<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\GuideFaq;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AssistantTest extends TestCase
{
    use RefreshDatabase;

    private function member(): array
    {
        $business = Business::factory()->create(['status' => 'active']);
        $user = User::factory()->create(['is_active' => true, 'business_id' => $business->id]);

        return [$user, $business, $user->createToken('test')->plainTextToken];
    }

    public function test_member_chat_returns_provider_reply(): void
    {
        [$user, $business, $token] = $this->member();
        config(['assistant.api_key' => 'test-key']);

        Http::fake([
            '*' => Http::response(['choices' => [['message' => ['content' => 'Stock looks fine.']]]], 200),
        ]);

        $response = $this->withToken($token)->postJson('/api/v1/assistant/chat', [
            'messages' => [['role' => 'user', 'content' => 'What is low on stock?']],
        ]);

        $response->assertOk()->assertJsonPath('data.reply', 'Stock looks fine.');

        Http::assertSent(function ($request) use ($business) {
            $payload = $request->data();
            return ($payload['model'] ?? null) === config('assistant.model')
                && str_contains($payload['messages'][0]['content'] ?? '', $business->name);
        });
    }

    public function test_guest_guide_needs_no_auth(): void
    {
        config(['assistant.api_key' => 'test-key']);
        Http::fake([
            '*' => Http::response(['choices' => [['message' => ['content' => 'Custosell does POS.']]]], 200),
        ]);

        $this->postJson('/api/v1/assistant/guide', [
            'messages' => [['role' => 'user', 'content' => 'What can Custosell do?']],
        ])->assertOk()->assertJsonPath('data.reply', 'Custosell does POS.');
    }

    public function test_chat_validates_messages(): void
    {
        [$user, $business, $token] = $this->member();

        $this->withToken($token)->postJson('/api/v1/assistant/chat', [
            'messages' => [],
        ])->assertStatus(422);
    }

    public function test_missing_key_returns_safe_error(): void
    {
        [$user, $business, $token] = $this->member();
        config(['assistant.api_key' => '']);

        $this->withToken($token)->postJson('/api/v1/assistant/chat', [
            'messages' => [['role' => 'user', 'content' => 'Hello']],
        ])->assertStatus(502)->assertJsonPath('message', 'The assistant is not connected yet. Add an API key to start chatting.');
    }

    public function test_knowledge_base_faq_reaches_provider(): void
    {
        config(['assistant.api_key' => 'test-key']);
        GuideFaq::query()->create([
            'uuid' => 'kb-test-faq-1',
            'question' => 'How do I restock inventory items?',
            'answer' => 'Open Inventory, pick the product, record a stock movement.',
            'sort_order' => 1,
            'is_published' => true,
        ]);

        Http::fake([
            '*' => Http::response(['choices' => [['message' => ['content' => 'Here is how.']]]], 200),
        ]);

        $this->postJson('/api/v1/assistant/guide', [
            'messages' => [['role' => 'user', 'content' => 'How do I restock inventory?']],
        ])->assertOk();

        Http::assertSent(function ($request) {
            $system = $request->data()['messages'][0]['content'] ?? '';
            return str_contains($system, 'How do I restock inventory items?')
                && str_contains($system, 'record a stock movement');
        });
    }

    public function test_live_pricing_reaches_provider(): void
    {
        config(['assistant.api_key' => 'test-key']);
        Plan::query()->create([
            'name' => 'Professional',
            'slug' => 'professional-test',
            'type' => 'business',
            'description' => 'For growing shops',
            'features' => ['sales' => true, 'inventory' => true],
            'limits' => [],
            'price_monthly_usd' => 29.99,
            'price_yearly_usd' => 299.90,
            'onboarding_fee_usd' => 0,
            'trial_days' => 30,
            'billing_cycle' => 'monthly',
            'is_popular' => true,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        Http::fake([
            '*' => Http::response(['choices' => [['message' => ['content' => 'It costs $29.99.']]]], 200),
        ]);

        $this->postJson('/api/v1/assistant/guide', [
            'messages' => [['role' => 'user', 'content' => 'How much does the Professional plan cost?']],
        ])->assertOk();

        Http::assertSent(function ($request) {
            $system = $request->data()['messages'][0]['content'] ?? '';
            return str_contains($system, 'Professional')
                && str_contains($system, '$29.99')
                && str_contains($system, '30-day trial');
        });
    }

    public function test_unknown_questions_offer_human_support(): void
    {
        config(['assistant.api_key' => 'test-key']);
        Http::fake([
            '*' => Http::response(['choices' => [['message' => ['content' => 'Not sure.']]]], 200),
        ]);

        $this->postJson('/api/v1/assistant/guide', [
            'messages' => [['role' => 'user', 'content' => 'How do I charter a helicopter?']],
        ])->assertOk();

        Http::assertSent(function ($request) {
            $system = $request->data()['messages'][0]['content'] ?? '';
            return str_contains($system, 'support@custosell.com')
                && str_contains($system, '+256 756 697 871');
        });
    }

    public function test_route_path_reaches_provider(): void
    {
        config(['assistant.api_key' => 'test-key']);
        Http::fake([
            '*' => Http::response(['choices' => [['message' => ['content' => 'Open the URL.']]]], 200),
        ]);

        $this->postJson('/api/v1/assistant/guide', [
            'messages' => [['role' => 'user', 'content' => 'Where do I make a sale?']],
        ])->assertOk();

        Http::assertSent(function ($request) {
            $system = $request->data()['messages'][0]['content'] ?? '';
            $base = rtrim((string) env('FRONTEND_URL', config('app.url')), '/');
            return str_contains($system, $base.'/sales/new');
        });
    }
}
