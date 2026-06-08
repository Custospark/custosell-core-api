<?php

namespace App\Services\Platform;

use App\Mail\StandardEmail;
use App\Models\Business;
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
        $loginUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/').'/login';
        $reasonLine = '<p><strong>Reason:</strong> '.e($reason).'</p>';

        [$title, $body, $ctaLabel, $ctaUrl, $tip] = match ($status) {
            'suspended' => [
                'Your Custosell business account has been suspended',
                '<p>Hello,</p>'
                    .'<p>Your business <strong>'.e($businessName).'</strong> has been suspended on Custosell.</p>'
                    .$reasonLine
                    .'<p>While suspended, you and your staff cannot sign in or use Custosell until the account is reactivated.</p>'
                    .'<p>If you believe this is a mistake, please contact Custospark support.</p>',
                'Contact Support',
                'mailto:support@custospark.com',
                'Only an active business account can access sales, inventory, and reports.',
            ],
            'restricted' => [
                'Your Custosell business account has been restricted',
                '<p>Hello,</p>'
                    .'<p>Your business <strong>'.e($businessName).'</strong> has been restricted on Custosell.</p>'
                    .$reasonLine
                    .'<p>While restricted, you and your staff cannot sign in until the restriction is lifted.</p>'
                    .'<p>Please contact Custospark support to resolve this.</p>',
                'Contact Support',
                'mailto:support@custospark.com',
                'Resolve the issue promptly to restore access for your team.',
            ],
            'warning' => [
                'Important notice about your Custosell business account',
                '<p>Hello,</p>'
                    .'<p>Your business <strong>'.e($businessName).'</strong> has received an account warning on Custosell.</p>'
                    .$reasonLine
                    .'<p>Your account remains active, but please address this matter to avoid further action.</p>',
                'Sign in to Custosell',
                $loginUrl,
                'Ignoring warnings may lead to account restriction or suspension.',
            ],
            'notified' => [
                'Notification recorded for your Custosell business',
                '<p>Hello,</p>'
                    .'<p>Your business <strong>'.e($businessName).'</strong> has been marked as notified on Custosell.</p>'
                    .$reasonLine
                    .'<p>Your account remains fully active. This status is for platform tracking after a communication was sent.</p>',
                'Sign in to Custosell',
                $loginUrl,
                'No action is required unless you received a separate message asking you to respond.',
            ],
            default => [
                'Your Custosell business account has been reactivated',
                '<p>Hello,</p>'
                    .'<p>Your business <strong>'.e($businessName).'</strong> is now active on Custosell.</p>'
                    .$reasonLine
                    .'<p>You and your staff can sign in and continue using the platform.</p>',
                'Sign in to Custosell',
                $loginUrl,
                'Welcome back — your data and settings are unchanged.',
            ],
        };

        foreach (array_unique(array_filter([$ownerEmail, $businessEmail])) as $email) {
            $this->sendBrandedEmail($email, $title, $body, $ctaUrl, $ctaLabel, $tip);
        }
    }

    public function notifyBusinessMessage(
        Business $business,
        string $intention,
        string $message,
        ?string $subject = null,
    ): void {
        $title = $subject ?: $this->defaultSubjectForIntention($intention, $business->name);
        $reasonLine = '<p>'.nl2br(e($message)).'</p>';
        $loginUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/').'/login';

        [$body, $ctaLabel, $ctaUrl, $tip] = match ($intention) {
            'warning_notice' => [
                '<p>Hello,</p>'
                    .'<p>This is an important notice regarding your business <strong>'.e($business->name).'</strong> on Custosell.</p>'
                    .$reasonLine
                    .'<p>Please review and take action if required.</p>',
                'Sign in to Custosell',
                $loginUrl,
                'Addressing notices promptly helps keep your account in good standing.',
            ],
            'payment_reminder' => [
                '<p>Hello,</p>'
                    .'<p>This is a payment reminder for <strong>'.e($business->name).'</strong>.</p>'
                    .$reasonLine,
                'Contact Billing',
                'mailto:support@custospark.com',
                'Keep your subscription current to avoid service interruption.',
            ],
            'policy_update' => [
                '<p>Hello,</p>'
                    .'<p>We have a policy update that applies to <strong>'.e($business->name).'</strong>.</p>'
                    .$reasonLine,
                'Sign in to Custosell',
                $loginUrl,
                'Review policy changes to ensure your business stays compliant.',
            ],
            'reactivation_nudge' => [
                '<p>Hello,</p>'
                    .'<p>We noticed <strong>'.e($business->name).'</strong> has been quiet on Custosell lately.</p>'
                    .$reasonLine
                    .'<p>Sign back in to continue managing sales, inventory, and customers.</p>',
                'Sign in to Custosell',
                $loginUrl,
                'Your data is safe and waiting for you.',
            ],
            'announcement' => [
                '<p>Hello,</p>'
                    .'<p>A message from Custospark regarding <strong>'.e($business->name).'</strong>:</p>'
                    .$reasonLine,
                'Sign in to Custosell',
                $loginUrl,
                null,
            ],
            default => [
                '<p>Hello,</p>'
                    .'<p>A message from Custospark regarding <strong>'.e($business->name).'</strong>:</p>'
                    .$reasonLine,
                'Sign in to Custosell',
                $loginUrl,
                null,
            ],
        };

        foreach ($this->businessRecipientEmails($business) as $email) {
            $this->sendBrandedEmail($email, $title, $body, $ctaUrl, $ctaLabel, $tip);
        }
    }

    /** @return list<string> */
    private function businessRecipientEmails(Business $business): array
    {
        return array_values(array_unique(array_filter([
            $business->owner?->email,
            $business->email,
        ])));
    }

    private function defaultSubjectForIntention(string $intention, string $businessName): string
    {
        return match ($intention) {
            'warning_notice' => "Important notice for {$businessName}",
            'payment_reminder' => "Payment reminder for {$businessName}",
            'policy_update' => "Policy update for {$businessName}",
            'reactivation_nudge' => "We'd love to see {$businessName} back on Custosell",
            'announcement' => "Announcement for {$businessName}",
            default => "Message from Custospark for {$businessName}",
        };
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
