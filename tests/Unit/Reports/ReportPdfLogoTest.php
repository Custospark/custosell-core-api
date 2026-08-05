<?php

namespace Tests\Unit\Reports;

use App\Models\Business;
use App\Services\ReportExportService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReportPdfLogoTest extends TestCase
{
    protected function tearDown(): void
    {
        Storage::disk('public')->deleteDirectory('business-logos');
        parent::tearDown();
    }

    private function businessWithLogo(): Business
    {
        Storage::disk('public')->makeDirectory('business-logos');

        // 1x1 transparent PNG.
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        );

        Storage::disk('public')->put('business-logos/test-logo.png', $png);

        return Business::make([
            'name' => 'Ace Hardware Ltd',
            'currency' => 'UGX',
            'logo_path' => '/storage/business-logos/test-logo.png',
        ]);
    }

    public function test_base_layout_embeds_business_logo_as_data_uri(): void
    {
        $business = $this->businessWithLogo();

        $html = view('reports.layouts.base', [
            'business' => $business,
            'reportTitle' => 'Daily Sales Report',
            'accent' => '#1e40af',
            'formatter' => app(ReportExportService::class),
        ])->render();

        $this->assertStringContainsString('class="header-logo"', $html);
        $this->assertStringContainsString('border-radius: 6px', $html);
        $this->assertStringContainsString('data:image/png;base64,', $html);
    }

    public function test_base_layout_omits_logo_when_business_has_none(): void
    {
        $business = Business::make(['name' => 'No Logo Shop', 'currency' => 'UGX']);

        $html = view('reports.layouts.base', [
            'business' => $business,
            'reportTitle' => 'Daily Sales Report',
            'accent' => '#1e40af',
            'formatter' => app(ReportExportService::class),
        ])->render();

        $this->assertStringNotContainsString('data:image/png;base64,', $html);
    }

    public function test_dompdf_renders_pdf_with_embedded_logo(): void
    {
        $business = $this->businessWithLogo();

        $pdf = app(ReportExportService::class)->renderPdfBytes('reports.layouts.base', [
            'business' => $business,
            'reportTitle' => 'Daily Sales Report',
            'accent' => '#1e40af',
            'formatter' => app(ReportExportService::class),
        ]);

        $this->assertNotEmpty($pdf);
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_logo_missing_on_disk_renders_cleanly(): void
    {
        $business = Business::make([
            'name' => 'Broken Logo Shop',
            'currency' => 'UGX',
            'logo_path' => '/storage/business-logos/does-not-exist.png',
        ]);

        $html = view('reports.layouts.base', [
            'business' => $business,
            'reportTitle' => 'Daily Sales Report',
            'accent' => '#1e40af',
            'formatter' => app(ReportExportService::class),
        ])->render();

        $this->assertStringNotContainsString('data:image/png;base64,', $html);
    }
}