<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssistantSessionsTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected Business $business;
    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

        $this->owner = User::factory()->create(['is_active' => true]);
        $this->business = Business::factory()->create([
            'owner_id' => $this->owner->id,
            'currency' => 'UGX',
            'status' => 'active',
        ]);
        $this->owner->business_id = $this->business->id;
        $this->owner->save();
        $this->token = $this->owner->createToken('test')->plainTextToken;
    }

    public function test_session_crud_is_scoped_to_owner(): void
    {
        $service = app(\App\Services\Contracts\ChatSessionServiceInterface::class);
        $session = $service->start($this->owner->id, $this->business->id, 'How did sales do today?');
        $service->appendTurn($session, 'How did sales do today?', 'Sales total UGX 100,000.');

        $listed = $this->getJson('/api/v1/assistant/sessions', ['Authorization' => 'Bearer ' . $this->token]);
        $listed->assertOk();
        $this->assertSame('How did sales do today?', $listed->json('data.0.title'));
        $this->assertSame(2, $listed->json('data.0.messages_count'));

        $shown = $this->getJson("/api/v1/assistant/sessions/{$session->id}", ['Authorization' => 'Bearer ' . $this->token]);
        $shown->assertOk();
        $this->assertCount(2, $shown->json('data.messages'));

        $renamed = $this->patchJson(
            "/api/v1/assistant/sessions/{$session->id}",
            ['title' => 'Today sales'],
            ['Authorization' => 'Bearer ' . $this->token]
        );
        $renamed->assertOk();
        $this->assertSame('Today sales', $renamed->json('data.title'));

        // Another user cannot see, open, or delete it. Guards are flushed
        // between users: the test container is shared across calls, so without
        // this Sanctum would keep serving the first memoized user.
        $intruder = User::factory()->create(['is_active' => true, 'business_id' => $this->business->id]);
        $intruderToken = $intruder->createToken('test')->plainTextToken;
        auth()->forgetGuards();
        $this->getJson("/api/v1/assistant/sessions/{$session->id}", ['Authorization' => 'Bearer ' . $intruderToken])
            ->assertNotFound();
        $this->deleteJson("/api/v1/assistant/sessions/{$session->id}", [], ['Authorization' => 'Bearer ' . $intruderToken])
            ->assertNotFound();

        auth()->forgetGuards();
        $this->deleteJson("/api/v1/assistant/sessions/{$session->id}", [], ['Authorization' => 'Bearer ' . $this->token])
            ->assertNoContent();
        $this->assertDatabaseMissing('chat_sessions', ['id' => $session->id]);
        $this->assertDatabaseMissing('chat_messages', ['chat_session_id' => $session->id]);
    }
}
