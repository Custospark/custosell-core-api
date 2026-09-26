<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FiscalCredentialRequest;
use App\Http\Resources\FiscalCredentialCollection;
use App\Http\Resources\FiscalCredentialResource;
use App\Services\Contracts\FiscalCredentialServiceInterface;
use App\Services\ModuleAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FiscalCredentialController extends Controller
{
    public function __construct(
        protected FiscalCredentialServiceInterface $credentialService,
        protected ModuleAccessService $moduleAccess,
    ) {}

    private function ensureOwner(Request $request): void
    {
        if (! $this->moduleAccess->isBusinessOwner($request->user())) {
            abort(403, 'Only the business owner can manage fiscal credentials.');
        }
    }

    public function index(Request $request): FiscalCredentialCollection
    {
        $this->ensureOwner($request);

        return new FiscalCredentialCollection(
            $this->credentialService->getAll((int) $request->user()->business_id)
        );
    }

    public function show(Request $request, int $id): FiscalCredentialResource
    {
        $this->ensureOwner($request);
        $credential = $this->credentialService->getById($id);
        if (! $credential || (int) $credential->business_id !== (int) $request->user()->business_id) {
            abort(404, 'Fiscal credentials not found');
        }

        return new FiscalCredentialResource($credential);
    }

    public function store(FiscalCredentialRequest $request): JsonResponse
    {
        $this->ensureOwner($request);
        $credential = $this->credentialService->create(
            (int) $request->user()->business_id,
            $request->validated()
        );

        return response()->json(['data' => new FiscalCredentialResource($credential)], 201);
    }

    public function update(FiscalCredentialRequest $request, int $id): FiscalCredentialResource
    {
        $this->ensureOwner($request);
        $credential = $this->credentialService->getById($id);
        if (! $credential || (int) $credential->business_id !== (int) $request->user()->business_id) {
            abort(404, 'Fiscal credentials not found');
        }

        return new FiscalCredentialResource(
            $this->credentialService->update($id, $request->validated())
        );
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->ensureOwner($request);
        $credential = $this->credentialService->getById($id);
        if (! $credential || (int) $credential->business_id !== (int) $request->user()->business_id) {
            abort(404, 'Fiscal credentials not found');
        }

        $this->credentialService->delete($id);

        return response()->json(null, 204);
    }
}
