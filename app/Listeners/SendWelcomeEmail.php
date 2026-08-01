<?php

namespace App\Listeners;

use App\Events\UserRegistered;
use App\Mail\StandardEmail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendWelcomeEmail
{
    public function handle(UserRegistered $event): void
    {
        $user = $event->user;
        $business = $event->business ?? $user->business;
        $isBusiness = $user->account_type === 'business';

        $brandName = config('brand.name', 'Custosell');
        $firstName = trim(explode(' ', (string) $user->name, 2)[0] ?? $user->name);
        $businessLine = $business
            ? ' for <strong>' . e($business->name) . '</strong>'
            : '';

        $intro = $isBusiness
            ? 'Everything your business needs to sell, manage, and grow — all in one system that works with or without the internet:'
            : 'Project Management, Productivity, Expense Tracking, Bookkeeping, Document Management &amp; more — stay organized and productive, even offline.';

        $showcase = $isBusiness ? $this->businessShowcase() : $this->personalShowcase();

        $quickStart = $isBusiness
            ? '<li style="margin-bottom:8px;">Add your products and set up categories</li>
               <li style="margin-bottom:8px;">Record your first sale at the point of sale</li>
               <li style="margin-bottom:8px;">Track inventory and stock levels</li>
               <li style="margin-bottom:8px;">Invite your team and manage staff shifts</li>'
            : '<li style="margin-bottom:8px;">Organize your projects and tasks</li>
               <li style="margin-bottom:8px;">Track expenses and keep your books tidy</li>
               <li style="margin-bottom:8px;">Manage and store your documents</li>
               <li style="margin-bottom:8px;">Stay productive, even when offline</li>';

        $tip = $isBusiness
            ? $brandName . ' works fully offline — sales, inventory, and customers keep running without internet, and everything syncs when you are back online.'
            : $brandName . ' works fully offline — projects, tasks, expenses, and documents keep working without internet, and everything syncs when you are back online.';

        $mailBody = '
            <p>Hello <strong>' . e($user->name) . '</strong>,</p>
            <p>Welcome aboard — your ' . $brandName . ' account' . $businessLine . ' is ready to go.</p>
            <p>' . $intro . '</p>
            ' . $showcase . '
            <p>Here is what you can do right away:</p>
            <ul style="margin:0 0 1.5em; padding-left:20px;">
                ' . $quickStart . '
            </ul>
            <p>We are excited to have you on board. If you ever need a hand, our team is here for you.</p>
        ';

        try {
            Mail::to($user->email)->send(new StandardEmail(
                title: 'Welcome to ' . $brandName . ', ' . $firstName . '!',
                mailBody: $mailBody,
                ctaUrl: rtrim(config('app.frontend_url', 'http://localhost:5173'), '/'),
                ctaLabel: 'Get Started',
                tip: $tip,
                logoPath: $this->logoDataUri(),
                isHtml: true,
            ));
        } catch (\Throwable $e) {
            Log::warning('Welcome email could not be sent', [
                'user_id' => $user->id ?? null,
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function businessShowcase(): string
    {
        $features = [
            'Point of Sale',
            'E-commerce Storefront',
            'Inventory &amp; Stock Control',
            'Customers',
            'Invoicing &amp; Payments',
            'Expenses',
            'Accounting &amp; Bookkeeping',
            'CRM &amp; Pipelines',
            'Estimates &amp; Quotations',
            'Document Management',
            'HR &amp; Payroll',
            'Forecasting',
            'B2B Marketplace &amp; Supply Chain',
            'Reports &amp; Analytics',
        ];

        $rows = '';
        foreach (array_chunk($features, 2) as $pair) {
            $rows .= '<tr>'
                . '<td width="50%" style="vertical-align:top; padding:3px 8px 3px 0; font-size:14px;">&bull; ' . $pair[0] . '</td>'
                . (isset($pair[1])
                    ? '<td width="50%" style="vertical-align:top; padding:3px 0 3px 8px; font-size:14px;">&bull; ' . $pair[1] . '</td>'
                    : '<td width="50%"></td>')
                . '</tr>';
        }

        return '<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin:0 0 1.5em;">'
            . $rows
            . '</table>';
    }

    protected function personalShowcase(): string
    {
        return '<ul style="margin:0 0 1.5em; padding-left:20px;">'
            . '<li style="margin-bottom:8px;">Project Management</li>'
            . '<li style="margin-bottom:8px;">Productivity Tools</li>'
            . '<li style="margin-bottom:8px;">Expense Tracking</li>'
            . '<li style="margin-bottom:8px;">Bookkeeping</li>'
            . '<li style="margin-bottom:8px;">Document Management</li>'
            . '</ul>';
    }

    protected function logoDataUri(): ?string
    {
        $path = public_path('images/custosell-logo-email.png');
        if (! file_exists($path)) {
            return null;
        }

        return 'data:image/png;base64,' . base64_encode((string) file_get_contents($path));
    }
}
