<?php

namespace App\Services\Contracts;

use App\Models\FiscalCredential;
use Illuminate\Database\Eloquent\Collection;

interface FiscalCredentialServiceInterface
{
    public function getAll(int $businessId): Collection;

    public function getById(int $id): ?FiscalCredential;

    /**
     * Resolve decrypted credentials for a business/location scope.
     * Branch row wins; business-default row is the fallback; null when none.
     *
     * @return array{credential: FiscalCredential, tin: string, device_no: string, branch_id: ?string, api_username: string, api_password: string, private_key_path: ?string, public_key_path: ?string, environment: string, country: string}|null
     */
    public function resolveForScope(int $businessId, ?int $locationId): ?array;

    public function create(int $businessId, array $data): FiscalCredential;

    public function update(int $id, array $data): FiscalCredential;

    public function delete(int $id): bool;
}
