{{-- resources/views/emails/academy-cohort3-urgent.blade.php --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Only a few Cohort 3 seats left</title>
    <style>
        body {
            margin: 0; padding: 0;
            background-color: #03152B;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto,
                         'Helvetica Neue', Arial, sans-serif;
            color: #111827;
        }
        .email-container {
            max-width: 600px; margin: 32px auto;
            background: #ffffff; border-radius: 12px;
            box-shadow: 0 6px 30px rgba(0,0,0,0.35);
            border: 1px solid #e5e7eb;
            overflow: hidden;
        }
        .email-container::before {
            content: ''; display: block; height: 6px;
            background: linear-gradient(90deg, #FF8A00, #dc2626);
        }
        .header { padding: 28px 24px 0; text-align: center; }
        .brand-name { font-size: 22px; font-weight: 700; color: #03152B; }
        .tagline { font-size: 13px; color: #FF8A00; font-weight: 600; margin-top: 2px; }
        .badge {
            display: inline-block; margin-top: 10px; padding: 6px 16px;
            background: #dc2626; color: #ffffff; font-size: 12px; font-weight: 700;
            letter-spacing: 1px; text-transform: uppercase; border-radius: 999px;
        }
        .divider { border: 0; height: 1px; background: #f3f4f6; margin: 18px 24px; }
        .body { padding: 8px 28px 32px; font-size: 15px; line-height: 1.7; color: #111827; }
        .body p { margin-bottom: 16px; }
        .hello { font-size: 16px; font-weight: 600; color: #111827; }
        .move {
            background: #fff7ed; border: 1px solid #fed7aa; border-radius: 10px;
            padding: 18px 20px; margin: 18px 0;
        }
        .move h3 { margin: 0 0 6px; font-size: 15px; font-weight: 700; color: #c2410c; }
        .move p { margin: 0; font-size: 14px; color: #374151; }
        .cta-wrap { text-align: center; margin: 26px 0 10px; }
        .cta {
            display: inline-block; padding: 14px 34px; background: #FF8A00;
            color: #ffffff !important; text-decoration: none; font-weight: 700;
            border-radius: 8px; box-shadow: 0 4px 6px rgba(255,138,0,0.35);
        }
        .cta-secondary {
            display: inline-block; padding: 12px 28px; background: #ffffff;
            color: #087CFF !important; text-decoration: none; font-weight: 600;
            border-radius: 8px; border: 2px solid #087CFF; margin-top: 10px;
        }
        .footer {
            background: linear-gradient(135deg, #03152B, #0D2D4A);
            padding: 26px 24px; text-align: center; font-size: 12px; color: #C5D2E0;
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
            <div class="brand-name">Custospark Academy</div>
            <div class="tagline">Learn. Build. Launch.</div>
            <div><span class="badge">Limited seats &middot; Cohort 3</span></div>
        </div>
        <hr class="divider">

        <div class="body">
            <p class="hello">Hi {{ $firstName }},</p>

            <p>I didn't expect this. The response to Custospark Academy's Cohort 3 launch
            has been overwhelming - applications are coming in far faster than the seats
            we have.</p>

            <p>So here is the honest position: <strong>slots are now limited, and
            applications may close well before 18 September.</strong> If getting into tech
            this year matters to you - or to someone you know - don't wait for the
            deadline. It may not wait for you.</p>

            <div class="move">
                <h3>1. Apply now</h3>
                <p>Data Science, Machine Learning, Mobile Development or Web Development.
                Two months, live instructor-led classes with recordings. UGX 25,000
                application fee - <strong>tuition fully sponsored</strong>.</p>
            </div>

            <div class="move">
                <h3>2. Forward this email</h3>
                <p>Send it to one colleague or friend who keeps saying they want to get
                into tech. You might change their year.</p>
            </div>

            <div class="cta-wrap">
                <a class="cta" href="https://academy.custospark.com/register">Apply now</a><br>
                <a class="cta-secondary" href="https://academy.custospark.com/">Explore the Academy</a>
            </div>

            <p>Karibu sana to the Academy!<br><br>Warm regards,<br><strong>Opiyo Oscar</strong><br>Founder &amp; CEO,<br>AI &amp; Technology Corporate Strategist<br>Custospark Academy<br>academy@custospark.com &middot; +256 756 697 871<br>Custospark Company Ltd.</p>
        </div>

        <div class="footer">
            <strong>Custospark Academy</strong> is by <strong>Custospark Company Ltd</strong> - the same company behind <strong>Custosell</strong> and <strong>Custocare</strong>.<br>
            <a href="mailto:academy@custospark.com">academy@custospark.com</a> &middot; +256 756 697 871<br>
            &copy; {{ $year }} Custospark Company Ltd, Kampala, Uganda.
        </div>

    </div>
</body>
</html>
