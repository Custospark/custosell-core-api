<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Queue\SerializesModels;

class ClassmateMarketingMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public const SUBJECT = 'Built by one of us - meet Custosell';

    public function __construct(
        public string $firstName,
        public int $year,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: self::SUBJECT,
            from: new Address(
                config('mail.from.address'),
                'Custospark Company Ltd',
            ),
        );
    }

    public function content(): Content
    {
        $logoCid = null;
        $logoPath = public_path('images/custosell-logo-email.png');
        if (file_exists($logoPath)) {
            $logoCid = $this->embed($logoPath);
        }

        return new Content(
            view: 'emails.classmate',
            with: [
                'firstName' => $this->firstName,
                'year' => $this->year,
                'logoCid' => $logoCid,
            ],
        );
    }
}