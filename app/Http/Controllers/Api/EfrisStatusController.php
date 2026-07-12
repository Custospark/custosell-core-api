<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Services\Efris\EfrisServiceInterface;
use Illuminate\Http\JsonResponse;

/**
 * Safe EFRIS status for Settings — never exposes credentials or key paths.
 */
class EfrisStatusController
{
    public function __construct(
        private readonly EfrisServiceInterface $efris,
    ) {}

    public function __invoke(): JsonResponse
    {
        return response()->json($this->efris->publicStatus());
    }
}
