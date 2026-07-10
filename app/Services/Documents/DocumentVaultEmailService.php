<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Mail\CustomerDocumentEmail;
use App\Models\Business;
use App\Models\Document;
use App\Models\DocumentFolder;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentVaultEmailService
{
    public function __construct(
        protected DocumentAccessService $access,
        protected DocumentFolderService $folders,
    ) {}

    /**
     * @return array{sent_to: string, sent_at: string, document_type: string, document_ref: string, email_sent_count: int, last_emailed_at: string|null}
     */
    public function sendFile(int $businessId, User $sender, int $documentId, string $to, ?string $customMessage = null): array
    {
        $document = Document::query()
            ->where('business_id', $businessId)
            ->whereKey($documentId)
            ->firstOrFail();

        $this->access->assertCanView($sender, $document);

        $business = Business::query()->findOrFail($businessId);
        $this->assertValidRecipient($to);

        if ($document->type === 'link') {
            abort(422, 'Web links cannot be emailed as attachments. Share the URL instead.');
        }

        if (! $document->file_path || ! Storage::disk('public')->exists($document->file_path)) {
            abort(422, 'This file has no downloadable attachment.');
        }

        $attachmentName = $document->file_name ?: $document->title;
        $mime = $document->mime_type ?: 'application/octet-stream';
        $bytes = Storage::disk('public')->get($document->file_path);

        $businessName = $business->name ?: 'Your business';
        $subject = sprintf('Shared file: %s from %s', $document->title, $businessName);
        $title = sprintf('Shared file: %s', $document->title);
        $body = $this->buildVaultBody($sender->name, $businessName, $document->title, $customMessage, false);

        $this->dispatch($to, $subject, $title, $body, $business, $attachmentName, $bytes, $mime);

        $this->recordDocumentEmailSent($document);

        return $this->buildSendResult($to, 'vault_file', $document->title, $document);
    }

    /**
     * @return array{sent_to: string, sent_at: string, document_type: string, document_ref: string, email_sent_count: int, last_emailed_at: string|null}
     */
    public function sendFolder(int $businessId, User $sender, int $folderId, string $to, ?string $customMessage = null): array
    {
        $folder = DocumentFolder::query()
            ->where('business_id', $businessId)
            ->whereKey($folderId)
            ->firstOrFail();

        $this->access->assertCanView($sender, $folder);

        $business = Business::query()->findOrFail($businessId);
        $this->assertValidRecipient($to);

        $zip = $this->folders->buildFolderZipBytes($businessId, $sender, $folderId);
        $businessName = $business->name ?: 'Your business';
        $subject = sprintf('Shared folder: %s from %s', $folder->name, $businessName);
        $title = sprintf('Shared folder: %s', $folder->name);
        $body = $this->buildVaultBody($sender->name, $businessName, $folder->name, $customMessage, true);

        $this->dispatch(
            $to,
            $subject,
            $title,
            $body,
            $business,
            $zip['filename'],
            $zip['bytes'],
            'application/zip',
        );

        return [
            'sent_to' => $to,
            'sent_at' => now()->toISOString(),
            'document_type' => 'vault_folder',
            'document_ref' => $folder->name,
            'email_sent_count' => 1,
            'last_emailed_at' => now()->toISOString(),
        ];
    }

    private function assertValidRecipient(string $to): void
    {
        if (! filter_var(trim($to), FILTER_VALIDATE_EMAIL)) {
            abort(422, 'A valid recipient email address is required.');
        }
    }

    private function buildVaultBody(
        string $senderName,
        string $businessName,
        string $itemName,
        ?string $customMessage,
        bool $isFolder,
    ): string {
        $kind = $isFolder ? 'folder' : 'file';
        $parts = [
            '<p>Hello,</p>',
            '<p><strong>' . e($senderName) . '</strong> from <strong>' . e($businessName) . '</strong> shared a ' . $kind . ' with you: <strong>' . e($itemName) . '</strong>.</p>',
        ];

        if ($customMessage !== null && trim($customMessage) !== '') {
            $parts[] = '<p>' . nl2br(e(trim($customMessage))) . '</p>';
        }

        $parts[] = '<p>The ' . $kind . ' is attached to this email.</p>';
        $parts[] = '<p style="color:#64748b;font-size:14px;margin-top:1.5em;">This message was sent on behalf of <strong>' . e($businessName) . '</strong> via Custosell.</p>';

        return implode("\n", $parts);
    }

    private function dispatch(
        string $to,
        string $subject,
        string $title,
        string $body,
        Business $business,
        string $attachmentName,
        string $attachmentBytes,
        string $mime,
    ): void {
        $replyTo = $this->resolveBusinessReplyTo($business);

        try {
            Mail::to($to)->send(new CustomerDocumentEmail(
                subjectLine: $subject,
                title: $title,
                mailBody: $body,
                businessName: $business->name ?: 'Your business',
                replyToEmail: $replyTo,
                fileAttachments: [[
                    'data' => $attachmentBytes,
                    'name' => $this->sanitizeAttachmentName($attachmentName),
                    'mime' => $mime,
                ]],
            ));
        } catch (\Throwable $e) {
            Log::error('Vault document email failed', [
                'to' => $to,
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);

            abort(500, 'Could not send email. Please try again or check your mail configuration.');
        }
    }

    private function resolveBusinessReplyTo(Business $business): ?string
    {
        foreach ([$business->business_email ?? null, $business->email ?? null] as $candidate) {
            $email = trim((string) $candidate);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        }

        return null;
    }

    private function sanitizeAttachmentName(string $filename): string
    {
        $filename = Str::replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], '-', $filename);

        return $filename !== '' ? $filename : 'document';
    }

  private function recordDocumentEmailSent(Document $document): void
    {
        $document->update([
            'email_sent_count' => (int) $document->email_sent_count + 1,
            'last_emailed_at' => now(),
        ]);
        $document->refresh();
    }

    /**
     * @return array{sent_to: string, sent_at: string, document_type: string, document_ref: string, email_sent_count: int, last_emailed_at: string|null}
     */
    private function buildSendResult(string $to, string $type, string $ref, Document $document): array
    {
        return [
            'sent_to' => $to,
            'sent_at' => now()->toISOString(),
            'document_type' => $type,
            'document_ref' => $ref,
            'email_sent_count' => (int) $document->email_sent_count,
            'last_emailed_at' => $document->last_emailed_at?->toISOString(),
        ];
    }
}
