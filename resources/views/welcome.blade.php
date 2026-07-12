<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Custosell') }} API</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet" />
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f9fafb;
            color: #111827;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }
        .card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            padding: 3rem;
            max-width: 480px;
            width: 100%;
            text-align: center;
        }
        .logo {
            font-size: 1.75rem;
            font-weight: 700;
            color: #2563eb;
            margin-bottom: 0.5rem;
        }
        .tagline {
            font-size: 0.875rem;
            color: #6b7280;
            margin-bottom: 2rem;
        }
        .badge {
            display: inline-block;
            background: #eff6ff;
            color: #2563eb;
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.375rem 1rem;
            border-radius: 9999px;
            margin-bottom: 1.5rem;
        }
        h1 {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
        }
        p {
            font-size: 0.875rem;
            color: #6b7280;
            line-height: 1.6;
            margin-bottom: 2rem;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: #2563eb;
            color: #ffffff;
            font-size: 0.875rem;
            font-weight: 500;
            padding: 0.75rem 2rem;
            border-radius: 10px;
            text-decoration: none;
            transition: background 0.2s;
        }
        .btn:hover { background: #1d4ed8; }
        .btn svg { width: 16px; height: 16px; }
        .footer {
            margin-top: 2rem;
            font-size: 0.75rem;
            color: #9ca3af;
        }
        .footer a { color: #6b7280; text-decoration: underline; }
        .status-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            background: #16a34a;
            border-radius: 50%;
            margin-right: 0.5rem;
            animation: pulse 2s infinite;
        }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">{{ config('brand.name', 'Custosell') }}</div>
        <div class="tagline">{{ config('brand.tagline', 'Your Business Operating System') }}</div>
        <div class="badge">
            <span class="status-dot"></span>
            API Operational
        </div>
        <h1>Backend API</h1>
        <p>
            This is the {{ config('brand.name', 'Custosell') }} API server. The frontend application is hosted separately.
            Head over to the main application to get started.
        </p>
        <a href="{{ config('brand.url', 'https://www.custosell.com') }}" class="btn" target="_blank" rel="noopener noreferrer">
            Go to Custosell.com
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>
            </svg>
        </a>
        <div class="footer">
            &copy; {{ date('Y') }}
            <a href="{{ config('brand.company_url', 'https://www.custospark.com') }}" target="_blank" rel="noopener noreferrer">{{ config('brand.company_name', 'Custospark Company Ltd') }}</a>
        </div>
    </div>
</body>
</html>
