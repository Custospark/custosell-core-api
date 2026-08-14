<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Custosell / Custospark brand logos for backend-generated documents.
 *
 * Logos live under `public/images` (copied from the Frontend assets) and are
 * embedded as base64 data URIs so DomPDF can render them without a web server.
 *
 * Files:
 * - `custosell-logo-pdf.png`   - Custosell product logo (PDF-optimized).
 * - `custospark-logo-pdf.png`  - Custospark Company logo (PDF-optimized).
 */
class BrandLogo
{
    /** @return string|null Base64 data URI, or null when the file is missing. */
    public static function custosellDataUri(): ?string
    {
        return self::dataUri('custosell-logo-pdf.png');
    }

    /** @return string|null Base64 data URI, or null when the file is missing. */
    public static function custosparkDataUri(): ?string
    {
        return self::dataUri('custospark-logo-pdf.png');
    }

    private static function dataUri(string $file): ?string
    {
        $path = public_path('images/'.$file);
        if (! is_file($path)) {
            return null;
        }

        $mime = mime_content_type($path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
    }
}
