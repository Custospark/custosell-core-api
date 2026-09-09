{{-- resources/views/emails/academy-cohort3.blade.php --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Custospark Academy - Cohort 3 applications open</title>
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
            background: linear-gradient(90deg, #087CFF, #FF8A00);
        }
        .header { padding: 28px 24px 0; text-align: center; }
        .brand-name { font-size: 22px; font-weight: 700; color: #03152B; }
        .tagline { font-size: 13px; color: #FF8A00; font-weight: 600; margin-top: 2px; }
        .badge {
            display: inline-block; margin-top: 10px; padding: 6px 16px;
            background: #FF8A00; color: #ffffff; font-size: 12px; font-weight: 700;
            letter-spacing: 1px; text-transform: uppercase; border-radius: 999px;
        }
        .divider { border: 0; height: 1px; background: #f3f4f6; margin: 18px 24px; }
        .body { padding: 8px 28px 32px; font-size: 15px; line-height: 1.7; color: #111827; }
        .body p { margin-bottom: 16px; }
        .hello { font-size: 16px; font-weight: 600; color: #111827; }
        .benefit {
            background: #f0f6ff; border: 1px solid #bfdbfe; border-radius: 10px;
            padding: 18px 20px; margin: 18px 0;
        }
        .benefit h3 { margin: 0 0 6px; font-size: 15px; font-weight: 700; color: #087CFF; }
        .benefit p { margin: 0; font-size: 14px; color: #374151; }
        .benefit.programs { background: #fff7ed; border-color: #fed7aa; }
        .benefit.programs h3 { color: #c2410c; }
        .deadline {
            background: #03152B; color: #ffffff; border-radius: 10px;
            padding: 16px 20px; margin: 20px 0; text-align: center; font-size: 15px;
        }
        .deadline strong { color: #FF8A00; }
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
            <div><span class="badge">Cohort 3 &middot; Now Online</span></div>
        </div>
        <hr class="divider">

        <div class="body">
            <p class="hello">Hi {{ $firstName }},</p>

            <p>Big news from our team: <strong>Custospark Academy is now live online</strong>,
            and applications for <strong>Cohort 3</strong> are officially open. Three things
            in it for you:</p>

            <div class="benefit programs">
                <h3>1. Enrol in a sponsored program</h3>
                <p>Data Science, Machine Learning, Mobile Development and Web Development -
                two months of live, instructor-led classes with recordings you can rewatch.
                You only pay a <strong>UGX 25,000 application fee</strong>;
                <strong>tuition is fully sponsored</strong>.</p>
            </div>

            <div class="benefit">
                <h3>2. Become an instructor or tutor</h3>
                <p>Know your craft? Join Custospark Academy as an instructor or tutor, teach
                live cohorts, and earn while shaping the next generation of builders. Just
                reply to this email telling us what you teach.</p>
            </div>

            <div class="benefit">
                <h3>3. Share the poster in your network</h3>
                <p>We have attached the Cohort 3 call-for-applications poster. One share in
                your class, alumni or WhatsApp groups could be someone's breakthrough -
                kindly pass it on.</p>
            </div>

            <div class="deadline">
                Applications close <strong>18 September</strong> &middot; Duration: <strong>2 months</strong>
            </div>

            <div class="cta-wrap">
                <a class="cta" href="https://academy.custospark.com/register">Apply now</a><br>
                <a class="cta-secondary" href="https://academy.custospark.com/">Explore the Academy</a>
            </div>

            <p>Karibu sana,<br><strong>Opiyo Oscar</strong><br>Founder &amp; CEO, AI &amp; Technology Corporate Strategist<br>Custospark Academy</p>
        </div>

        <div class="footer">
            <strong>Custospark Academy</strong> &middot; An institution of Custospark Company Ltd<br>
            <a href="mailto:academy@custospark.com">academy@custospark.com</a> &middot; +256 756 697 871<br>
            &copy; {{ $year }} Custospark Company Ltd, Kampala, Uganda.
        </div>

    </div>
</body>
</html>
