{{-- resources/views/emails/standard.blade.php --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            background-color: #f4f6f8;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto,
                         'Helvetica Neue', Arial, sans-serif;
            color: #333;
        }

        .email-container {
            position: relative;
            max-width: 600px;
            margin: 40px auto;
            background-color: #ffffff;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 6px 30px rgba(0, 0, 0, 0.08);
            border: 1px solid #e5e7eb;
        }

        .email-container::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            background: linear-gradient(90deg, #2563eb 0%, #1e40af 100%);
            z-index: 1;
        }

        .email-header {
            padding: 32px 24px 0;
            text-align: center;
        }

        .logo-rounded {
            border-radius: 50%;
            max-height: 64px;
            margin-bottom: 12px;
            background-color: white;
            padding: 4px;
            border: 2px solid #e5e7eb;
        }

        .brand-section { margin-bottom: 16px; }
        .brand-name { font-size: 24px; font-weight: 700; color: #1e293b; margin-bottom: 4px; line-height: 1.2; letter-spacing: -0.3px; }
        .tagline { font-size: 14px; color: #64748b; font-weight: 400; margin-bottom: 8px; }
        .parent-brand { font-size: 11px; color: #94a3b8; font-style: italic; font-weight: 400; margin-bottom: 0; }
        .parent-brand a { color: #94a3b8; }

        .brand-divider {
            border: 0;
            height: 1px;
            background: #e5e7eb;
            margin: 16px 24px;
        }

        .email-header h1 {
            margin: 20px 24px 0;
            padding: 20px 0 0;
            font-size: 20px;
            font-weight: 500;
            color: #111827;
        }

        .email-body {
            padding: 36px 28px;
            font-size: 16px;
            line-height: 1.75;
            color: #111827;
        }

        .email-body p { margin-bottom: 1.5em; }

        .cta-button {
            display: inline-block;
            margin: 24px 0;
            padding: 14px 32px;
            background: #2563eb;
            color: #ffffff !important;
            text-decoration: none;
            font-weight: 600;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(37, 99, 235, 0.25);
        }

        .email-tip {
            background: #eff6ff;
            border-left: 4px solid #2563eb;
            padding: 20px;
            margin: 24px 0;
            border-radius: 8px;
            font-size: 15px;
            color: #1e40af;
        }

        .email-tip strong { color: #2563eb; }

        .email-footer {
            background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%);
            padding: 32px 24px;
            text-align: center;
            font-size: 13px;
            color: #e2e8f0;
            line-height: 1.8;
        }

        .footer-message { margin-bottom: 16px; }
        .footer-message strong { color: #ffffff; font-weight: 600; }
        .footer-attribution { font-size: 12px; font-style: italic; color: #f1f5f9; margin-bottom: 12px; }
        .footer-attribution a { color: #f1f5f9; text-decoration: underline; }
        .copyright { font-size: 12px; color: #cbd5e1; }

        @media only screen and (max-width: 620px) {
            .email-container { margin: 20px 10px; }
            .email-body { padding: 24px 16px; }
            .email-header h1 { font-size: 18px; }
            .brand-name { font-size: 20px; }
        }
    </style>
</head>
<body>
    <div class="email-container">

        {{-- ── Header ──────────────────────────────────────────────────── --}}
        <div class="email-header">
            @php
                $logoToUse = $logoPath ?? public_path('images/custosell-logo.png');
                $logoExists = file_exists($logoToUse);
            @endphp

            @if($logoExists)
                <img src="{{ $message->embed($logoToUse) }}"
                     alt="{{ config('app.name') }}"
                     class="logo-rounded">
            @else
                <div style="font-size: 32px; font-weight: bold; margin-bottom: 12px; color: #1e293b;">📧</div>
            @endif

            <div class="brand-section">
                <div class="brand-name">Custosell</div>
                <div class="tagline">Sell More. Track All. Grow Fast.</div>
                <div class="parent-brand">
                    a product of
                    <a href="https://www.custospark.com">Custospark Company Ltd</a>
                </div>
            </div>

            <hr class="brand-divider">

            <h1>{{ $title }}</h1>
        </div>

        {{-- ── Body ───────────────────────────────────────────────────── --}}
        <div class="email-body">

            {{-- Main content: render HTML or escape plain text --}}
            @if($isHtml)
                {!! $mailBody !!}
            @else
                <p>{!! nl2br(e($mailBody)) !!}</p>
            @endif

            {{-- Optional pro-tip callout --}}
            @isset($tip)
                <div class="email-tip">
                    <strong>Pro Tip:</strong> {{ $tip }}
                </div>
            @endisset

            {{-- Optional CTA button --}}
            @isset($ctaUrl)
                <div style="text-align: center;">
                    <a href="{{ $ctaUrl }}"
                       class="cta-button"
                       target="_blank"
                       rel="noopener noreferrer">
                        {{ $ctaLabel ?? 'Get Started' }}
                    </a>
                </div>
            @endisset

        </div>

        {{-- ── Footer ─────────────────────────────────────────────────── --}}
        <div class="email-footer">
            <div class="footer-message">
                You're receiving this because you have an account with<br>
                <strong>Custosell</strong> &mdash; Sell More. Track All. Grow Fast.
            </div>
            <div class="footer-attribution">
                a product of
                <a href="https://www.custospark.com">Custospark Company Ltd</a>
            </div>
            <div class="copyright">
                &copy; {{ now()->year }} Custospark Company Ltd. All rights reserved.
            </div>
        </div>

    </div>
</body>
</html>
