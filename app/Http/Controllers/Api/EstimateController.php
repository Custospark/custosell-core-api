<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SendDocumentEmailRequest;
use App\Http\Requests\StoreEstimateRequest;
use App\Http\Requests\UpdateEstimateStatusRequest;
use App\Http\Resources\EstimateCollection;
use App\Http\Resources\EstimateResource;
use App\Http\Resources\EstimateTemplateResource;
use App\Http\Resources\EstimateVersionResource;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\ProjectResource;
use App\Services\Contracts\EstimateServiceInterface;
use App\Services\CustomerDocumentEmailService;
use App\Services\EstimatePdfBuilder;
use App\Services\ReportExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class EstimateController extends Controller
{
    public function __construct(
        protected EstimateServiceInterface $estimateService,
        protected ReportExportService $export,
        protected EstimatePdfBuilder $estimatePdfBuilder,
        protected CustomerDocumentEmailService $documentEmailService,
    ) {}

    public function index(Request $request): EstimateCollection
    {
        $businessId = $request->user()->business_id;
        $filters = $request->only(['status', 'customer_id', 'date_from', 'date_to']);

        return new EstimateCollection(
            $this->estimateService->getAll($businessId, $filters)
        );
    }

    public function show(int $id): EstimateResource
    {
        $estimate = $this->estimateService->getById($id);
        if (!$estimate) {
            abort(404, 'Estimate not found');
        }

        return new EstimateResource($estimate);
    }

    public function store(StoreEstimateRequest $request): JsonResponse
    {
        $estimate = $this->estimateService->create(
            $request->user()->business_id,
            $request->user()->id,
            $request->validated(),
        );

        return response()->json(new EstimateResource($estimate), 201);
    }

    public function update(StoreEstimateRequest $request, int $id): EstimateResource
    {
        $estimate = $this->estimateService->update($id, $request->validated());

        return new EstimateResource($estimate);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->estimateService->delete($id);

        return response()->json(null, 204);
    }

    public function send(UpdateEstimateStatusRequest $request, int $id): JsonResponse
    {
        try {
            $estimate = $this->estimateService->send(
                $id,
                $request->user()->id,
                $request->validated('change_summary'),
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(new EstimateResource($estimate));
    }

    public function approve(UpdateEstimateStatusRequest $request, int $id): JsonResponse
    {
        try {
            $estimate = $this->estimateService->approve(
                $id,
                $request->validated('approved_by_name'),
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(new EstimateResource($estimate));
    }

    public function reject(UpdateEstimateStatusRequest $request, int $id): JsonResponse
    {
        try {
            $estimate = $this->estimateService->reject(
                $id,
                (string) $request->validated('rejection_reason'),
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(new EstimateResource($estimate));
    }

    public function email(SendDocumentEmailRequest $request, int $id): JsonResponse
    {
        $estimate = $this->estimateService->getById($id);
        if (!$estimate) {
            abort(404, 'Estimate not found');
        }

        if ((int) $estimate->business_id !== (int) $request->user()->business_id) {
            abort(404, 'Estimate not found');
        }

        $estimate->loadMissing(['customer']);
        $to = trim((string) ($request->validated('to') ?? ''));
        if ($to === '') {
            $to = $this->documentEmailService->resolveCustomerEmail($estimate->customer) ?? '';
        }

        if ($to === '') {
            return response()->json([
                'message' => 'No recipient email. Add a customer email or enter one manually.',
            ], 422);
        }

        try {
            $result = $this->documentEmailService->sendEstimate(
                $estimate,
                $request->user()->business,
                $to,
                $request->validated('message'),
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json($result);
    }

    public function downloadPdf(Request $request, int $id): Response
    {
        $estimate = $this->estimateService->getById($id);
        if (!$estimate) {
            abort(404, 'Estimate not found');
        }

        if ((int) $estimate->business_id !== (int) $request->user()->business_id) {
            abort(404, 'Estimate not found');
        }

        $business = $request->user()->business;
        $pdfConfig = $this->estimatePdfBuilder->build($estimate, $business);

        return $this->export->downloadPdf(
            $pdfConfig['view'],
            $pdfConfig['data'],
            $pdfConfig['filename'],
            $pdfConfig['orientation'],
        );
    }

    public function duplicate(Request $request, int $id): JsonResponse
    {
        try {
            $estimate = $this->estimateService->duplicate($id, $request->user()->id);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(new EstimateResource($estimate), 201);
    }

    public function versions(int $id): AnonymousResourceCollection
    {
        $estimate = $this->estimateService->getById($id);
        if (!$estimate) {
            abort(404, 'Estimate not found');
        }

        return EstimateVersionResource::collection($estimate->versions);
    }

    public function createRevision(StoreEstimateRequest $request, int $id): JsonResponse
    {
        try {
            $estimate = $this->estimateService->createRevision(
                $id,
                $request->user()->id,
                $request->validated(),
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(new EstimateResource($estimate));
    }

    public function convertToInvoice(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'issue_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'send' => ['nullable', 'boolean'],
        ]);

        try {
            $invoice = $this->estimateService->convertToInvoice(
                $id,
                $request->user()->id,
                $data,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(new InvoiceResource($invoice), 201);
    }

    public function convertToProject(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:planning,active,on_hold,completed,cancelled'],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
        ]);

        try {
            $project = $this->estimateService->convertToProject(
                $id,
                $request->user()->id,
                $data,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(new ProjectResource($project), 201);
    }

    public function analytics(Request $request): JsonResponse
    {
        $summary = $this->estimateService->analyticsSummary($request->user()->business_id);

        return response()->json(['data' => $summary]);
    }

    public function templates(Request $request): AnonymousResourceCollection
    {
        return EstimateTemplateResource::collection(
            $this->estimateService->getTemplates($request->user()->business_id)
        );
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'line_items_template' => ['required', 'array', 'min:1'],
            'terms' => ['nullable', 'string'],
            'default_tax_rate' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $template = $this->estimateService->createTemplate(
            $request->user()->business_id,
            $request->user()->id,
            $data,
        );

        return response()->json(new EstimateTemplateResource($template), 201);
    }

    public function showTemplate(int $id): EstimateTemplateResource
    {
        $template = $this->estimateService->getTemplates(auth()->user()->business_id)
            ->firstWhere('id', $id);

        if (!$template) {
            abort(404, 'Estimate template not found');
        }

        return new EstimateTemplateResource($template);
    }

    public function updateTemplate(Request $request, int $id): EstimateTemplateResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'line_items_template' => ['sometimes', 'array', 'min:1'],
            'terms' => ['nullable', 'string'],
            'default_tax_rate' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        try {
            $template = $this->estimateService->updateTemplate($id, $data);
        } catch (\RuntimeException $e) {
            abort(404, $e->getMessage());
        }

        return new EstimateTemplateResource($template);
    }

    public function destroyTemplate(int $id): JsonResponse
    {
        try {
            $this->estimateService->deleteTemplate($id);
        } catch (\RuntimeException $e) {
            abort(404, $e->getMessage());
        }

        return response()->json(null, 204);
    }
}
