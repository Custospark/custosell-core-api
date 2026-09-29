<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InvestmentItem;
use App\Services\InvestmentQuotationService;
use App\Services\ReportExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class QuotationController extends Controller
{
    public function __construct(
        private InvestmentQuotationService $quotations,
        private ReportExportService $export,
    ) {}

    /** Public package catalog: tiers with pricing, maintenance and recommended kit. */
    public function packages(): JsonResponse
    {
        return response()->json(['data' => $this->quotations->packages()]);
    }

    /** Web estimator page (Blade + vanilla JS, no app build needed). */
    public function page(): \Illuminate\View\View
    {
        return view('quotations.estimator', [
            'packages' => $this->quotations->packages(),
            'items' => InvestmentItem::query()->where('is_active', true)->orderBy('sort_order')->get(['code', 'category', 'name', 'price_ugx']),
        ]);
    }

    /**
     * Quote recipient: the signed-in user's business when authenticated,
     * otherwise the guest-supplied name. Reads like a quote from Custosell
     * to the other business either way.
     *
     * @return array{name: string|null, email: string|null, phone: string|null}
     */
    private function clientFor(Request $request, ?string $guestName): array
    {
        $user = $request->user();
        $business = $user?->business;
        if ($business) {
            return [
                'name' => (string) ($business->name ?? $user->name ?? ''),
                'email' => $business->business_email ?? $business->email,
                'phone' => $business->business_phone ?? $business->phone,
            ];
        }
        if ($user) {
            return ['name' => (string) ($user->name ?? ''), 'email' => $user->email, 'phone' => $user->phone ?? null];
        }

        return ['name' => $guestName, 'email' => null, 'phone' => null];
    }

    /** Shared validation for the JSON estimator and the PDF download form. */
    private function validatedPayload(Request $request): array
    {
        return $request->validate([
            'plan' => ['required', 'string', 'max:64'],
            'billing' => ['sometimes', 'string', 'in:monthly,yearly'],
            'drivers' => ['sometimes', 'array'],
            'drivers.tills' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'drivers.staff' => ['sometimes', 'integer', 'min:1', 'max:5000'],
            'drivers.branches' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'items' => ['sometimes', 'array', 'max:100'],
            'items.*.code' => ['required_with:items', 'string', 'max:64'],
            'items.*.qty' => ['required_with:items', 'integer', 'min:1', 'max:10000'],
            'custom_lines' => ['sometimes', 'array', 'max:50'],
            'custom_lines.*.label' => ['required_with:custom_lines', 'string', 'max:120'],
            'custom_lines.*.amount_ugx' => ['required_with:custom_lines', 'numeric', 'min:0', 'max:1000000000'],
            'custom_fields' => ['sometimes', 'array', 'max:20'],
            'custom_fields.*.label' => ['required_with:custom_fields', 'string', 'max:80'],
            'custom_fields.*.value' => ['required_with:custom_fields'],
            'discount_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'vat_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'rep_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'rep_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'customer_name' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);
    }

    /** @return array<string, mixed> */
    private function quoted(array $data): array
    {
        try {
            return $this->quotations->estimate(
                $data['plan'],
                $data['drivers'] ?? [],
                $data['items'] ?? [],
                $data['billing'] ?? 'monthly',
                $data['custom_lines'] ?? [],
                (float) ($data['discount_percent'] ?? 0),
                (float) ($data['vat_percent'] ?? 0),
                $data['custom_fields'] ?? [],
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            abort(404, 'Plan not found');
        }
    }

    /** Public estimator: plan + drivers + optional extra rows -> full totals. */
    public function estimate(Request $request): JsonResponse
    {
        $data = $this->validatedPayload($request);
        $quote = $this->quoted($data);
        $quote['client'] = $this->clientFor($request, null);

        return response()->json(['data' => $quote]);
    }

    /** Public PDF download of the same quotation. */
    public function download(Request $request): Response
    {
        $data = $this->validatedPayload($request);
        $quote = $this->quoted($data);
        $quote['client'] = $this->clientFor($request, $data['customer_name'] ?? null);
        $quote['rep'] = [
            'name' => $data['rep_name'] ?? null,
            'phone' => $data['rep_phone'] ?? null,
        ];

        $logoFile = public_path('images/custosell-logo-pdf.png');
        $brand = [
            'name' => (string) config('brand.name', 'Custosell'),
            'tagline' => (string) config('brand.tagline', ''),
            'url' => (string) config('brand.url', 'https://www.custosell.com'),
            'company' => (string) config('brand.company_name', 'Custospark Company Ltd'),
            'footer' => (string) config('brand.footer', ''),
            'logo' => is_file($logoFile) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($logoFile)) : null,
        ];

        return $this->export->downloadPdf(
            'pdf.quotation',
            ['quote' => $quote, 'customerName' => $data['customer_name'] ?? null, 'brand' => $brand],
            'custosell-quotation-'.($quote['plan']['slug'] ?? 'plan').'-'.now()->format('Ymd'),
        );
    }
}
