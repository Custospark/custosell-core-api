{{-- resources/views/emails/classmate.blade.php --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Custosell - built by one of us</title>
    <style>
        body {
            margin: 0; padding: 0;
            background-color: #f9fafb;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto,
                         'Helvetica Neue', Arial, sans-serif;
            color: #111827;
        }
        .email-container {
            max-width: 600px; margin: 32px auto;
            background: #ffffff; border-radius: 12px;
            box-shadow: 0 6px 30px rgba(0,0,0,0.08);
            border: 1px solid #e5e7eb;
            overflow: hidden;
        }
        .email-container::before {
            content: ''; display: block; height: 4px;
            background: linear-gradient(90deg, #2563eb, #1e40af);
        }
        .header { padding: 28px 24px 0; text-align: center; }
        .brand-name { font-size: 22px; font-weight: 700; color: #111827; }
        .tagline { font-size: 13px; color: #6b7280; margin-top: 2px; }
        .divider { border: 0; height: 1px; background: #f3f4f6; margin: 18px 24px; }
        .body { padding: 8px 28px 32px; font-size: 15px; line-height: 1.7; color: #111827; }
        .body p { margin-bottom: 16px; }
        .hello { font-size: 16px; font-weight: 600; color: #111827; }
        .benefit {
            background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 10px;
            padding: 18px 20px; margin: 18px 0;
        }
        .benefit h3 { margin: 0 0 6px; font-size: 15px; font-weight: 700; color: #2563eb; }
        .benefit p { margin: 0; font-size: 14px; color: #374151; }
        .tip {
            background: #eff6ff; border-left: 4px solid #2563eb;
            padding: 16px 20px; margin: 20px 0; border-radius: 8px;
            font-size: 14px; color: #1e40af;
        }
        .cta-wrap { text-align: center; margin: 26px 0 10px; }
        .cta {
            display: inline-block; padding: 14px 34px; background: #2563eb;
            color: #ffffff !important; text-decoration: none; font-weight: 600;
            border-radius: 8px; box-shadow: 0 4px 6px rgba(37,99,235,0.25);
        }
        .footer {
            background: linear-gradient(135deg, #2563eb, #1e40af);
            padding: 26px 24px; text-align: center; font-size: 12px; color: #e2e8f0;
            line-height: 1.8;
        }
        .footer strong { color: #ffffff; }
        .footer a { color: #f1f5f9; text-decoration: underline; }
        @media only screen and (max-width: 620px) {
            .email-container { margin: 16px 8px; }
            .body { padding: 4px 18px 24px; }
        }
    </style>
</head>
<body>
    <div class="email-container">

        <div class="header">
            @if(!empty($logoUrl))
                <img src="{{ $logoUrl }}" alt="Custosell" style="max-height:56px; width:auto; border-radius:50%; padding:4px; background:#fff; border:2px solid #e5e7eb; margin-bottom:12px;">
            @endif
            <div class="brand-name">{{ config('brand.name') }}</div>
            <div class="tagline">{{ config('brand.tagline') }}</div>
        </div>
        <hr class="divider">

        <div class="body">
            <p class="hello">Hi {{ $firstName }},</p>

            <p>I hope the semester is going well. I'm <strong>Opiyo Oscar</strong> - and
            I built something I want to share with our class first, because it literally
            started here.</p>

            <p>Together with <strong>{{ config('brand.company_name') }}</strong>, I built
            <strong>Custosell</strong>: one system for running a business - Point of Sale,
            an online store, inventory, accounting, invoicing, expenses, HR &amp; payroll,
            CRM, and more - that even works offline.</p>

            <div class="benefit">
                <h3>1. Create a free Personal account - get organized.</h3>
                <p>Custosell isn't only for businesses. With a Personal account you get
                project management, productivity tools, expense tracking, bookkeeping, and
                document management - all in one place, offline-ready. You can start free
                and upgrade whenever you like.</p>
            </div>

            <div class="benefit">
                <h3>2. Turn your network into income - with your own QR code.</h3>
                <p>The moment you create an account, Custosell automatically generates a
                personal referral QR code for you. When anyone uses it to subscribe to a
                Custosell plan, you earn a commission on what they actually pay - paid out
                monthly to your Mobile Money or bank account. There's no limit to how many
                businesses you can refer, and your dashboard tracks every earning.</p>
            </div>

            <div class="benefit">
                <h3>3. When you're ready to start something real - you already have the engine.</h3>
                <p>Planning a startup? Start with your Personal account today, and upgrade to a
                Business account when you're ready - Point of Sale, E-commerce Storefront,
                Inventory, Accounting, HR &amp; Payroll, Invoicing, Expenses, CRM, Forecasting
                &amp; more, all in one system that works with or without the internet. No
                re-inventing the wheel.</p>
            </div>

            <div class="tip">
                <strong>Built by one of us.</strong> Custosell grew out of our class. Whether
                you use it, or simply refer it to a business you know, we'd love for our class
                to be part of it.
            </div>

            <div class="cta-wrap">
                <a class="cta" href="https://custosell.com/register" target="_blank" rel="noopener noreferrer">
                    Create your free account
                </a>
            </div>
            <p style="text-align:center; font-size:13px; color:#6b7280;">
                Or explore at <a href="https://custosell.com" style="color:#2563eb;">custosell.com</a>
                - reply to this email and I'll walk you through it personally.
            </p>
        </div>

        <div class="footer">
            <div>
                You're receiving this because you're part of the class that inspired
                <strong>Custosell</strong>.
            </div>
            <div>
                A product of
                <a href="{{ config('brand.company_url') }}" target="_blank" rel="noopener noreferrer">{{ config('brand.company_name') }}</a>
                · {{ config('brand.company_city') }}, {{ config('brand.company_country') }}
            </div>
            <div>&copy; {{ $year }} {{ config('brand.company_name') }}. All rights reserved.</div>
        </div>

    </div>
</body>
</html>