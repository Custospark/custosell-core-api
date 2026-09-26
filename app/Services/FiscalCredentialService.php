<?php

namespace App\Services;

use App\Models\FiscalCredential;
use App\Repositories\Contracts\FiscalCredentialRepositoryInterface;
use App\Services\Contracts\FiscalCredentialServiceInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

class FiscalCredentialService implements FiscalCredentialServiceInterface
{
    public function __construct(
        protected FiscalCredentialRepositoryInterface $credentialRepository,
    ) {}

    public function getAll(int $businessId): Collection
    {
        return $this->credentialRepository->all($businessId);
    }

    public function getById(int $id): ?FiscalCredential
    {
        return $this->credentialRepository->find($id);
    }

    public function resolveForScope(int $businessId, ?int $locationId): ?array
    {
        $credential = $this->credentialRepository->findForScope($businessId, $locationId);
        if (! $credential) {
            return null;
        }

        try {
            $apiPassword = Crypt::decryptString($credential->getAttributes()['api_password']);
        } catch (\Throwable) {
            throw new RuntimeException('Stored fiscal credentials cannot be decrypted - re-save them.');
        }

        return [
            'credential' => $credential,
            'tin' => (string) $credential->tin,
            'device_no' => (string) $credential->device_no,
            'branch_id' => $credential->branch_id !== null ? (string) $credential->branch_id : null,
            'api_username' => (string) $credential->api_username,
            'api_password' => $apiPassword,
            'private_key_path' => $credential->private_key_path,
            'public_key_path' => $credential->public_key_path,
            'environment' => (string) ($credential->environment ?? 'sandbox'),
            'country' => strtoupper((string) ($credential->country ?? 'UG')),
        ];
    }

    public function create(int $businessId, array $data): FiscalCredential
    {
        $data['business_id'] = $businessId;
        if (isset($data['api_password'])) {
            $data['api_password'] = Crypt::encryptString((string) $data['api_password']);
        }

        return $this->credentialRepository->create($data);
    }

    public function update(int $id, array $data): FiscalCredential
    {
        $credential = $this->credentialRepository->find($id);
        if (! $credential) {
            throw new RuntimeException('Fiscal credentials not found');
        }

        // Blank password on update keeps the stored secret.
        if (array_key_exists('api_password', $data)) {
            if ($data['api_password'] === null || $data['api_password'] === '') {
                unset($data['api_password']);
            } else {
                $data['api_password'] = Crypt::encryptString((string) $data['api_password']);
            }
        }

        return $this->credentialRepository->update($credential, $data);
    }

    public function delete(int $id): bool
    {
        $credential = $this->credentialRepository->find($id);
        if (! $credential) {
            throw new RuntimeException('Fiscal credentials not found');
        }

        return $this->credentialRepository->delete($credential);
    }
}
