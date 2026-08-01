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

        $brandName = config('brand.name', 'Custosell');
        $firstName = trim(explode(' ', (string) $user->name, 2)[0] ?? $user->name);
        $businessLine = $business
            ? ' for <strong>' . e($business->name) . '</strong>'
            : '';

        $mailBody = '
            <p>Hello <strong>' . e($user->name) . '</strong>,</p>
            <p>Welcome aboard — your ' . $brandName . ' account' . $businessLine . ' is ready to go.</p>
            <p>' . $brandName . ' is your all-in-one business operating system: point of sale, inventory, customers, invoices, expenses, and more — all in one place. Built offline-first, it keeps working even when the internet drops.</p>
            <p>Here is what you can do right away:</p>
            <ul style="margin:0 0 1.5em; padding-left:20px;">
                <li style="margin-bottom:8px;">Add your products and set up categories</li>
                <li style="margin-bottom:8px;">Record your first sale at the point of sale</li>
                <li style="margin-bottom:8px;">Track inventory and stock levels</li>
                <li style="margin-bottom:8px;">Manage customers and send invoices</li>
            </ul>
            <p>We are excited to have you on board. If you ever need a hand, our team is here for you.</p>
        ';

        try {
            Mail::to($user->email)->send(new StandardEmail(
                title: 'Welcome to ' . $brandName . ', ' . $firstName . '!',
                mailBody: $mailBody,
                ctaUrl: rtrim(config('app.frontend_url', 'http://localhost:5173'), '/'),
                ctaLabel: 'Get Started',
                tip: $brandName . ' works fully offline — sales, inventory, and customers keep running without internet, and everything syncs when you are back online.',
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

    protected function logoDataUri(): ?string
    {
        $path = public_path('images/custosell-logo-email.png');
        if (! file_exists($path)) {
            return null;
        }

        return 'data:image/png;base64,' . base64_encode((string) file_get_contents($path));
    }
}
