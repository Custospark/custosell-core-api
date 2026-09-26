<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Location;
use App\Models\User;
use App\Services\Contracts\FiscalCredentialServiceInterface;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalCredentialVaultTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected Business $business;

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
    }

    private function vault(): FiscalCredentialServiceInterface
    {
        return app(FiscalCredentialServiceInterface::class);
    }

    public function test_password_is_encrypted_at_rest_and_never_serialized(): void
    {
        $credential = $this->vault()->create($this->business->id, [
            'tin' => '1000123456',
            'device_no' => 'DEV-1',
            'api_username' => 'api-user',
            'api_password' => 's3cret-pw',
        ]);

        $this->assertNotSame('s3cret-pw', $credential->getAttributes()['api_password']);
        $this->assertArrayNotHasKey('api_password', $credential->toArray());

        $resolved = $this->vault()->resolveForScope($this->business->id, null);
        $this->assertNotNull($resolved);
        $this->assertSame('s3cret-pw', $resolved['api_password']);
        $this->assertSame('1000123456', $resolved['tin']);
    }

    public function test_branch_row_wins_over_business_default(): void
    {
        $location = Location::create([
            'business_id' => $this->business->id,
            'name' => 'Kampala Depot',
            'code' => 'KLA-1',
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->vault()->create($this->business->id, [
            'tin' => '1000000001',
            'device_no' => 'DEV-DEFAULT',
            'api_username' => 'default-user',
            'api_password' => 'default-pw',
        ]);
        $this->vault()->create($this->business->id, [
            'location_id' => $location->id,
            'tin' => '1000000002',
            'device_no' => 'DEV-BRANCH',
            'api_username' => 'branch-user',
            'api_password' => 'branch-pw',
        ]);

        $branch = $this->vault()->resolveForScope($this->business->id, $location->id);
        $this->assertSame('DEV-BRANCH', $branch['device_no']);

        $fallback = $this->vault()->resolveForScope($this->business->id, 999999);
        $this->assertSame('DEV-DEFAULT', $fallback['device_no']);

        $this->assertNull($this->vault()->resolveForScope($this->business->id + 999999, null));
    }

    public function test_blank_password_on_update_keeps_stored_secret(): void
    {
        $credential = $this->vault()->create($this->business->id, [
            'tin' => '1000123456',
            'device_no' => 'DEV-1',
            'api_username' => 'api-user',
            'api_password' => 'original-pw',
        ]);

        $updated = $this->vault()->update($credential->id, [
            'tin' => '1000123456',
            'device_no' => 'DEV-1',
            'api_username' => 'api-user',
            'api_password' => '',
        ]);

        $resolved = $this->vault()->resolveForScope($this->business->id, null);
        $this->assertSame('original-pw', $resolved['api_password']);
        $this->assertSame($credential->id, $updated->id);
    }
}
