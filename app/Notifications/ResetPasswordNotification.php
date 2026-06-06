<?php

namespace App\Notifications;

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
        $frontendUrl = config('app.frontend_url', 'http://localhost:5173');
        if (!str_starts_with($frontendUrl, 'http://') && !str_starts_with($frontendUrl, 'https://')) {
            $frontendUrl = 'http://' . $frontendUrl;
        }
        $resetUrl = $frontendUrl . '/reset-password?token=' . $this->token . '&email=' . urlencode($notifiable->email);

        Mail::send('emails.standard', [
            'title' => 'Reset Your Custosell Password',
            'mailBody' => '
                <p>Hello <strong>' . e($notifiable->name) . '</strong>,</p>
                <p>You are receiving this email because we received a password reset request for your Custosell account.</p>
                <p style="font-size:14px; color:#64748b;">This password reset link will expire in 60 minutes.</p>
                <p style="font-size:14px; color:#64748b;">If you did not request a password reset, no further action is required.</p>
            ',
            'ctaUrl' => $resetUrl,
            'ctaLabel' => 'Reset My Password',
            'tip' => 'Never share this email with anyone. Custosell will never ask for your password.',
            'isHtml' => true,
        ], function ($message) use ($notifiable) {
            $message->to($notifiable->email)
                    ->subject('Reset Your Custosell Password');
        });
    }
}
