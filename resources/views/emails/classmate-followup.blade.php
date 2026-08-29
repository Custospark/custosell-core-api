{{-- resources/views/emails/classmate-followup.blade.php --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>A quick video to get you started with your Custosell account</title>
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
        .list-item { margin: 0 0 10px 0; }
        .video-box {
            background: #eff6ff; border-left: 4px solid #2563eb;
            padding: 18px 20px; margin: 22px 0; border-radius: 8px;
        }
        .video-box a { color: #2563eb; font-weight: 600; text-decoration: none; }
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
            @if(!empty($logoCid))
                <img src="{{ $logoCid }}" alt="{{ config('brand.name') }}" style="max-height:56px; width:auto; border-radius:50%; padding:4px; background:#fff; border:2px solid #e5e7eb; margin-bottom:12px;">
            @endif
            <div class="brand-name">{{ config('brand.name') }}</div>
            <div class="tagline">{{ config('brand.tagline') }}</div>
        </div>
        <hr class="divider">

        <div class="body">
            <p class="hello">Hi {{ $firstName }},</p>

            <p>Thank you for checking out Custosell - I really appreciate you giving it a look.</p>

            <p>To make it easier to get the most out of your account, I put together a short
            video walking you through how to get started. It covers the things I think you'll
            use most:</p>

            <ul style="padding-left:20px; margin:0 0 16px 0;">
                <li class="list-item"><strong>Project management</strong> - organise projects, tasks, and keep everything moving</li>
                <li class="list-item"><strong>Collaboration</strong> - work with teammates on shared boards</li>
                <li class="list-item"><strong>Productivity</strong> - stay organised with checklists, reminders, and a clean workflow</li>
                <li class="list-item"><strong>Expense tracking &amp; bookkeeping</strong> - keep your money organised as you go</li>
                <li class="list-item"><strong>Document management</strong> - keep important files in one place</li>
            </ul>

            @if(!empty($videoUrl))
                <div class="video-box">
                    You can watch it here:
                    <a href="{{ $videoUrl }}" target="_blank" rel="noopener noreferrer">{{ $videoUrl }}</a>
                </div>
            @endif

            <p>The video is the same way I use Custosell myself, so it's a real walkthrough
            rather than a scripted demo.</p>

            <p>And if anything isn't clear - or there's something you'd like to see covered -
            just reply and I'll help you out personally.</p>

            <div class="cta-wrap">
                <a class="cta" href="https://custosell.com" target="_blank" rel="noopener noreferrer">
                    Open Custosell
                </a>
            </div>
            <p style="text-align:center; font-size:13px; color:#6b7280;">
                Thanks again for your time. I hope this helps you get the most out of the platform.
            </p>
        </div>

        <div class="footer">
            <div>You're receiving this because you're part of the class that inspired <strong>Custosell</strong>.</div>
            <div>
                A product of
                <a href="{{ config('brand.company_url') }}" target="_blank" rel="noopener noreferrer">{{ config('brand.company_name') }}</a>
                - {{ config('brand.company_city') }}, {{ config('brand.company_country') }}
            </div>
            <div>&copy; {{ $year }} {{ config('brand.company_name') }}. All rights reserved.</div>
        </div>

    </div>
</body>
</html>