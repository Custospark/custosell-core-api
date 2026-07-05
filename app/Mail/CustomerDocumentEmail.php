<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class CustomerDocumentEmail extends Mailable
{
    /**
     * @param  array<int, array{data: string, name: string, mime: string}>  $fileAttachments
     */
    public function __construct(
        public readonly string $subjectLine,
        public readonly string $title,
        public readonly string $mailBody,
        public readonly string $businessName,
        public readonly ?string $replyToEmail = null,
        public readonly array $fileAttachments = [],
    ) {}

    public function envelope(): Envelope
    {
        $envelope = new Envelope(subject: $this->subjectLine);

        if ($this->replyToEmail !== null && $this->replyToEmail !== '') {
            $envelope = $envelope->replyTo([new Address($this->replyToEmail, $this->businessName)]);
        }

        return $envelope;
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.customer-document',
            with: [
                'title' => $this->title,
                'mailBody' => $this->mailBody,
                'businessName' => $this->businessName,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return array_map(
            fn (array $file): Attachment => Attachment::fromData(
                fn (): string => $file['data'],
                $file['name'],
            )->withMime($file['mime']),
            $this->fileAttachments,
        );
    }
}
