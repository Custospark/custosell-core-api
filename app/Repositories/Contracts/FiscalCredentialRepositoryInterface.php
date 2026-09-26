<?php

namespace App\Repositories\Contracts;

use App\Models\FiscalCredential;
use Illuminate\Database\Eloquent\Collection;

interface FiscalCredentialRepositoryInterface
{
    public function all(int $businessId): Collection;

    public function find(int $id): ?FiscalCredential;

    public function findForScope(int $businessId, ?int $locationId): ?FiscalCredential;

    public function create(array $data): FiscalCredential;

    public function update(FiscalCredential $credential, array $data): FiscalCredential;

    public function delete(FiscalCredential $credential): bool;
}
