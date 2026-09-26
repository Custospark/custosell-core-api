<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\GuideFaq;
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
}
