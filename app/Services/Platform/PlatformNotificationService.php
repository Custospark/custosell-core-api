<?php

namespace App\Services\Platform;

use App\Mail\StandardEmail;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class PlatformNotificationService
{
    public function sendToEmail(string $email, string $title, string $body): void
    {
        if ($email === '') {
            return;
        }

        try {
            Mail::to($email)->send(new StandardEmail(
                title: $title,
                mailBody: $body,
                isHtml: true,
            ));
        } catch (\Throwable $e) {
            Log::warning('Platform email failed', [
                'email' => $email,
                'subject' => $title,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function notifyBusinessStatusChange(
        string $businessName,
        ?string $ownerEmail,
        ?string $businessEmail,
        string $status,
        string $reason,
    ): void {
        $isSuspended = $status === 'suspended';
        $loginUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/').'/login';

        $title = $isSuspended
            ? 'Your Custosell business account has been suspended'
            : 'Your Custosell business account has been reactivated';

        $reasonLine = '<p><strong>Reason:</strong> '.e($reason).'</p>';

        if ($isSuspended) {
            $body = '<p>Hello,</p>'
                .'<p>Your business <strong>'.e($businessName).'</strong> has been suspended on Custosell.</p>'
                .$reasonLine
                .'<p>While suspended, you and your staff cannot sign in or use Custosell until the account is reactivated.</p>'
                .'<p>If you believe this is a mistake, please contact Custospark support.</p>';
            $ctaLabel = 'Contact Support';
            $ctaUrl = 'mailto:support@custospark.com';
            $tip = 'Only an active business account can access sales, inventory, and reports.';
        } else {
            $body = '<p>Hello,</p>'
                .'<p>Your business <strong>'.e($businessName).'</strong> has been reactivated on Custosell.</p>'
                .$reasonLine
                .'<p>You and your staff can sign in and continue using the platform.</p>';
            $ctaLabel = 'Sign in to Custosell';
            $ctaUrl = $loginUrl;
            $tip = 'Welcome back — your data and settings are unchanged.';
        }

        foreach (array_unique(array_filter([$ownerEmail, $businessEmail])) as $email) {
            $this->sendBrandedEmail($email, $title, $body, $ctaUrl, $ctaLabel, $tip);
        }
    }

    private function sendBrandedEmail(
        string $email,
        string $title,
        string $body,
        ?string $ctaUrl = null,
        ?string $ctaLabel = null,
        ?string $tip = null,
    ): void {
        if ($email === '') {
            return;
        }

        try {
            Mail::to($email)->send(new StandardEmail(
                title: $title,
                mailBody: $body,
                ctaUrl: $ctaUrl,
                ctaLabel: $ctaLabel,
                tip: $tip,
                isHtml: true,
            ));
        } catch (\Throwable $e) {
            Log::warning('Platform email failed', [
                'email' => $email,
                'subject' => $title,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function notifyUserStatusChange(User $user, bool $isActive, ?string $reason): void
    {
        $title = $isActive
            ? 'Your Custosell account has been reactivated'
            : 'Your Custosell account has been deactivated';

        $reasonLine = $reason ? "<p><strong>Reason:</strong> ".e($reason).'</p>' : '';
        $body = $isActive
            ? '<p>Hello '.e($user->name).',</p><p>Your Custosell account is active again. You can sign in normally.</p>'
            : '<p>Hello '.e($user->name).',</p><p>Your Custosell account has been deactivated.</p>'.$reasonLine;

        $this->sendToEmail($user->email, $title, $body);
    }
}
