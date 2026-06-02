<?php

namespace App\Repositories\Contracts;

use App\Models\Business;
use Illuminate\Database\Eloquent\Collection;

interface BusinessRepositoryInterface
{
    public function all(): Collection;

    public function find(int $id): ?Business;

    public function findBySlug(string $slug): ?Business;

    public function findByOwner(int $ownerId): ?Business;

    public function create(array $data): Business;

    public function update(Business $business, array $data): Business;

    public function delete(Business $business): bool;
}
