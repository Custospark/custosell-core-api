<?php

namespace App\Services\Contracts;

use App\Models\Business;

interface BusinessServiceInterface
{
    public function getById(int $id): ?Business;

    public function getByOwner(int $ownerId): ?Business;

    public function register(array $userData, array $businessData): Business;

    public function update(int $id, array $data): Business;

    public function updateSettings(int $id, array $data): Business;

    public function suspend(int $id): Business;
}
