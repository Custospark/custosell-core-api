<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\PushSubscription;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebPushSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Business $business;
    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

        $this->user = User::factory()->create(['is_active' => true]);
        $this->token = $this->user->createToken('test')->plainTextToken;

        $this->business = Business::factory()->create([
            'owner_id' => $this->user->id,
            'currency' => 'UGX',
            'status' => 'active',
        ]);
        $this->user->business_id = $this->business->id;
        $this->user->save();
    }

    /** @return array<string, string> */
    protected function authHeaders(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }

    protected function endpoint(string $path = ''): string
    {
        return '/api/v1/webpush'.$path;
    }

    private function validPayload(): array
    {
        return [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/demo-endpoint-1',
            'keys' => [
                'p256dh' => 'BP3FiFhdpx1GdoBg-Qh3AQxjD-K8z9yFOQ5T7V1Y1xvQNUnNLsOmGxRZx0FxB0XQnpqzhA5sXLh7A2d9Nf-0HfE',
                'auth' => 'VnM2bFZLcG9Yc3BhbGlhdGVkLXNlY3JldA',
            ],
        ];
    }

    public function test_status_reports_support_and_count(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson($this->endpoint('/subscribe'), $this->validPayload())
            ->assertStatus(201);

        $this->withHeaders($this->authHeaders())
            ->getJson($this->endpoint('/status'))
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.subscription_count', 1);
    }

    public function test_subscribe_stores_subscription(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson($this->endpoint('/subscribe'), $this->validPayload())
            ->assertStatus(201);

        $this->assertDatabaseHas('push_subscriptions', [
            'user_id' => $this->user->id,
            'business_id' => $this->business->id,
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/demo-endpoint-1',
        ]);
    }

    public function test_subscribe_is_idempotent_per_endpoint(): void
    {
        $payload = $this->validPayload();

        $this->withHeaders($this->authHeaders())->postJson($this->endpoint('/subscribe'), $payload)->assertStatus(201);
        $this->withHeaders($this->authHeaders())->postJson($this->endpoint('/subscribe'), $payload)->assertStatus(201);

        $this->assertDatabaseCount('push_subscriptions', 1);
    }

    public function test_subscribe_rejects_malformed_payload(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson($this->endpoint('/subscribe'), ['endpoint' => 'not-a-url', 'keys' => []])
            ->assertStatus(422);
    }

    public function test_unsubscribe_removes_subscription(): void
    {
        PushSubscription::create([
            'user_id' => $this->user->id,
            'business_id' => $this->business->id,
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/demo-endpoint-1',
            'public_key' => 'demo-p256dh',
            'auth_secret' => 'demo-auth',
        ]);

        $this->withHeaders($this->authHeaders())
            ->deleteJson($this->endpoint('/unsubscribe'), ['endpoint' => 'https://fcm.googleapis.com/fcm/send/demo-endpoint-1'])
            ->assertOk();

        $this->assertDatabaseMissing('push_subscriptions', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/demo-endpoint-1']);
    }

    public function test_unsubscribe_requires_endpoint(): void
    {
        $this->withHeaders($this->authHeaders())
            ->deleteJson($this->endpoint('/unsubscribe'))
            ->assertStatus(422);
    }

    public function test_webpush_requires_authentication(): void
    {
        $this->postJson($this->endpoint('/subscribe'), $this->validPayload())->assertStatus(401);
        $this->getJson($this->endpoint('/status'))->assertStatus(401);
    }
}
