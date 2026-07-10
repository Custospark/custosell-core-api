<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\Business;
use App\Models\User;

class DocumentVaultService
{
    public function __construct(
        protected DocumentAccessService $access,
    ) {}

    /** @return array{cover_color: string|null, background_type: string|null, background_value: string|null} */
    public function getAppearance(Business $business): array
    {
        return [
            'cover_color' => $business->documents_cover_color,
            'background_type' => $business->documents_background_type,
            'background_value' => $business->documents_background_value,
        ];
    }

    /** @param  array{cover_color?: string|null, background_type?: string|null, background_value?: string|null}  $payload
     * @return array{cover_color: string|null, background_type: string|null, background_value: string|null}
     */
    public function updateAppearance(Business $business, User $user, array $payload): array
    {
        $this->access->assertHasDocumentsModule($user);

        if (! $this->access->isOwner($user)) {
            abort(403, 'Only the business owner can change vault appearance.');
        }

        if (array_key_exists('cover_color', $payload)) {
            $business->documents_cover_color = $this->normalizeColor($payload['cover_color']);
        }

        if (array_key_exists('background_type', $payload)) {
            $type = $payload['background_type'];
            if ($type !== null && ! in_array($type, ['color', 'gallery'], true)) {
                abort(422, 'Invalid vault background type.');
            }
            $business->documents_background_type = $type;
        }

        if (array_key_exists('background_value', $payload)) {
            $business->documents_background_value = $payload['background_value'] !== null
                ? mb_substr(trim((string) $payload['background_value']), 0, 500)
                : null;
        }

        $business->save();

        return $this->getAppearance($business->fresh() ?? $business);
    }

    protected function normalizeColor(?string $color): ?string
    {
        if ($color === null || trim($color) === '') {
            return null;
        }

        $value = trim($color);
        if (! preg_match('/^#[0-9A-Fa-f]{3}([0-9A-Fa-f]{3})?$/', $value)) {
            abort(422, 'Invalid color value.');
        }

        return strtolower($value);
    }
}
