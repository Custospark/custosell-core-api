<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

    /** Public estimator: plan + drivers + optional extra rows -> full totals. */
    public function estimate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'string', 'max:64'],
            'billing' => ['sometimes', 'string', 'in:monthly,yearly'],
            'drivers' => ['sometimes', 'array'],
            'drivers.tills' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'drivers.staff' => ['sometimes', 'integer', 'min:1', 'max:5000'],
            'drivers.branches' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'items' => ['sometimes', 'array', 'max:100'],
            'items.*.code' => ['required_with:items', 'string', 'max:64'],
            'items.*.qty' => ['required_with:items', 'integer', 'min:1', 'max:10000'],
        ]);

        try {
            $quote = $this->quotations->estimate(
                $data['plan'],
                $data['drivers'] ?? [],
                $data['items'] ?? [],
                $data['billing'] ?? 'monthly',
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            abort(404, 'Plan not found');
        }

        $quote['client'] = $this->clientFor($request, null);

        return response()->json(['data' => $quote]);
    }

    /** Public PDF download of the same quotation. */
    public function download(Request $request): Response
    {
        $data = $request->validate([
            'plan' => ['required', 'string', 'max:64'],
            'billing' => ['sometimes', 'string', 'in:monthly,yearly'],
            'drivers' => ['sometimes', 'array'],
            'drivers.tills' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'drivers.staff' => ['sometimes', 'integer', 'min:1', 'max:5000'],
            'drivers.branches' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'items' => ['sometimes', 'array', 'max:100'],
            'items.*.code' => ['required_with:items', 'string', 'max:64'],
            'items.*.qty' => ['required_with:items', 'integer', 'min:1', 'max:10000'],
            'customer_name' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        try {
            $quote = $this->quotations->estimate(
                $data['plan'],
                $data['drivers'] ?? [],
                $data['items'] ?? [],
                $data['billing'] ?? 'monthly',
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            abort(404, 'Plan not found');
        }

        $quote['client'] = $this->clientFor($request, $data['customer_name'] ?? null);

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
