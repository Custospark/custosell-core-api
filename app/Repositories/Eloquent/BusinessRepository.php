<?php

namespace App\Repositories\Eloquent;

use App\Models\Business;
use App\Repositories\Contracts\BusinessRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class BusinessRepository implements BusinessRepositoryInterface
{
    public function all(): Collection
    {
        return Business::all();
    }

    public function find(int $id): ?Business
    {
        return Business::find($id);
    }

    public function findBySlug(string $slug): ?Business
    {
        return Business::where('slug', $slug)->first();
    }

    public function findByOwner(int $ownerId): ?Business
    {
        return Business::where('owner_id', $ownerId)->first();
    }

    public function create(array $data): Business
    {
        return Business::create($data);
    }

    public function update(Business $business, array $data): Business
    {
        $business->update($data);
        return $business->fresh();
    }

    public function delete(Business $business): bool
    {
        return $business->delete();
    }
}
