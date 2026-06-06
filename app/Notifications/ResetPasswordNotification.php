<?php

namespace App\Notifications;

use App\Mail\StandardEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Mail;

class ResetPasswordNotification extends Notification
{
    use Queueable;

    public string $token;

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): void
    {
        $frontendUrl = config('app.frontend_url', 'https://custosell.com');
        $resetUrl = $frontendUrl . '/reset-password?token=' . $this->token . '&email=' . urlencode($notifiable->email);

        $body = '
        <p>Hello <strong>' . e($notifiable->name) . '</strong>,</p>
        <p>You are receiving this email because we received a password reset request for your Custosell account.</p>
        <p style="font-size:14px; color:#64748b;">This password reset link will expire in 60 minutes.</p>
        <p style="font-size:14px; color:#64748b;">If you did not request a password reset, no further action is required.</p>';

        $email = new StandardEmail(
            title: 'Reset Your Custosell Password',
            mailBody: $body,
            ctaUrl: $resetUrl,
            ctaLabel: 'Reset My Password',
            tip: 'Never share this email with anyone. Custosell will never ask for your password.',
            logoPath: public_path('images/custosell-logo.png'),
        );

        Mail::to($notifiable->email)->send($email);
    }
}
