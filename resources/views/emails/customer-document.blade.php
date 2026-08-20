{{-- resources/views/emails/customer-document.blade.php --}}
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
            box-shadow: 0 6px 30px rgba(0, 0, 0, 0.08);
            border: 1px solid #e5e7eb;
            overflow: hidden;
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
            padding: 32px 28px 0;
            text-align: center;
        }

        .business-name {
            font-size: 20px;
            font-weight: 700;
            color: #111827;
            margin: 0 0 10px 0;
            letter-spacing: -0.3px;
        }

        .document-title {
            margin: 0;
            padding: 14px 0 0;
            border-top: 1px solid #e5e7eb;
            font-size: 13px;
            font-weight: 600;
            letter-spacing: 0.3px;
            text-transform: uppercase;
            color: #64748b;
        }

        .email-body {
            padding: 28px 28px 32px;
            font-size: 15px;
            line-height: 1.75;
            color: #111827;
        }

        .email-body p { margin: 0 0 1.25em 0; }
        .email-body p:last-child { margin-bottom: 0; }

        .attachment-note {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-left: 3px solid #2563eb;
            border-radius: 8px;
            padding: 14px 16px;
            margin-top: 24px;
            font-size: 14px;
            color: #475569;
        }

        .attachment-note strong { color: #1e293b; }

        .custosell-promo {
            background: #f8fafc;
            border-top: 1px solid #e5e7eb;
            padding: 16px 28px;
            text-align: center;
            font-size: 12px;
            color: #64748b;
            line-height: 1.5;
        }

        .custosell-promo a {
            color: #2563eb;
            text-decoration: none;
            font-weight: 600;
        }

        .custosell-promo a:hover { text-decoration: underline; }

        .email-footer {
            padding: 16px 28px;
            text-align: center;
            font-size: 12px;
            color: #94a3b8;
            line-height: 1.7;
            border-top: 1px solid #e5e7eb;
        }

        .email-footer a { color: #64748b; }

        @media only screen and (max-width: 620px) {
            .email-container { margin: 20px 10px; }
            .email-body, .email-header { padding-left: 20px; padding-right: 20px; }
        }
    </style>
</head>
<body>
    <div class="email-container">
        <div class="email-header">
            <p class="business-name">{{ $businessName }}</p>
            <p class="document-title">{{ $title }}</p>
        </div>

        <div class="email-body">
            {!! $mailBody !!}

            <div class="attachment-note">
                <strong>Attachment included</strong><br>
                Your document is attached to this email. Please keep it for your records.
            </div>
        </div>

        <div class="custosell-promo">
            Powered by <a href="{{ config('brand.url') }}" target="_blank" rel="noopener noreferrer">{{ config('brand.name') }}</a>
            &mdash; {{ config('brand.tagline') }}.
        </div>

        <div class="email-footer">
            Sent on behalf of {{ $businessName }} via {{ config('brand.name') }},
            a product of <a href="{{ config('brand.company_url') }}">{{ config('brand.company_name') }}</a>.<br>
            &copy; {{ now()->year }} {{ config('brand.company_name') }}. All rights reserved.
        </div>
    </div>
</body>
</html>
