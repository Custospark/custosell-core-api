<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentFileStorage
{
    public function __construct(
        protected DocumentAccessService $access,
        protected DocumentActivityService $activity,
    ) {}

    /** @return array{content: string, content_type: string, encoding: string, editable: bool, truncated: bool} */
    public function getFileContent(int $businessId, User $user, Document $document): array
    {
        $this->access->assertCanView($user, $document);

        if ($document->type === 'link' || ! $document->file_path) {
            abort(422, 'This document has no file content.');
        }

        $diskPath = Storage::disk('public')->path($document->file_path);
        if (! is_readable($diskPath)) {
            abort(404, 'File not found on disk.');
        }

        $maxBytes = (int) config('documents.max_text_content_size_kb', 2048) * 1024;
        $fileSize = (int) ($document->file_size ?? filesize($diskPath) ?: 0);
        $mime = strtolower((string) ($document->mime_type ?? ''));
        $fileName = (string) ($document->file_name ?? '');

        if ($this->isWordDocument($mime, $fileName)) {
            $content = $this->extractDocxText($diskPath);
            $truncated = strlen($content) > $maxBytes;
            if ($truncated) {
                $content = substr($content, 0, $maxBytes);
            }

            return [
                'content' => $content,
                'content_type' => 'word',
                'encoding' => 'utf-8',
                'editable' => false,
                'truncated' => $truncated,
            ];
        }

        if (! $this->isTextViewableMime($mime, $fileName)) {
            abort(422, 'Inline text viewing is not supported for this file type.');
        }

        if ($fileSize > $maxBytes) {
            abort(422, 'File is too large to view inline. Download the file instead.');
        }

        $raw = file_get_contents($diskPath);
        if ($raw === false) {
            abort(500, 'Could not read file.');
        }

        if (! mb_check_encoding($raw, 'UTF-8')) {
            abort(422, 'This file uses an encoding that cannot be displayed inline. Download the file instead.');
        }

        $contentType = $this->isCsvMime($mime, $fileName) ? 'csv' : 'text';

        return [
            'content' => $raw,
            'content_type' => $contentType,
            'encoding' => 'utf-8',
            'editable' => $this->access->canEditDocument($user, $document),
            'truncated' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function updateFileContent(int $businessId, User $user, Document $document, string $content): array
    {
        if (! $this->access->canEditDocument($user, $document)) {
            abort(403, 'You cannot edit this document.');
        }

        if ($document->type === 'link' || ! $document->file_path) {
            abort(422, 'This document has no file content.');
        }

        $mime = strtolower((string) ($document->mime_type ?? ''));
        $fileName = (string) ($document->file_name ?? '');

        if ($this->isWordDocument($mime, $fileName) || ! $this->isTextViewableMime($mime, $fileName)) {
            abort(422, 'This file type cannot be edited inline.');
        }

        $maxBytes = (int) config('documents.max_text_content_size_kb', 2048) * 1024;
        if (strlen($content) > $maxBytes) {
            abort(422, 'Content exceeds the maximum editable size of '.round($maxBytes / (1024 * 1024), 1).' MB.');
        }

        if (! mb_check_encoding($content, 'UTF-8')) {
            abort(422, 'Content must be valid UTF-8 text.');
        }

        $written = Storage::disk('public')->put($document->file_path, $content);
        if (! $written) {
            abort(500, 'Could not save file.');
        }

        $document->update([
            'file_size' => strlen($content),
        ]);

        $this->activity->record(
            $businessId,
            $user,
            'document_updated',
            'document',
            $document->id,
            $document->title,
            $document->folder_id,
            $document->cabinet_id,
        );

        return [];
    }

    public function deleteFileFromDisk(Document $document): void
    {
        if ($document->file_path) {
            Storage::disk('public')->delete($document->file_path);
        }
    }

    public function storagePath(int $businessId): string
    {
        return trim(config('documents.storage_folder', 'business-documents'), '/').'/'.$businessId;
    }

    public function isMediaMime(string $mime, ?string $fileName = null): bool
    {
        $mime = strtolower($mime);
        if (Str::startsWith($mime, ['audio/', 'video/'])) {
            return true;
        }

        $ext = $this->extensionOf($fileName);

        return in_array($ext, ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac', 'webm', 'mp4', 'mov', 'm4v', 'ogv'], true);
    }

    public function isWordDocument(string $mime, ?string $fileName = null): bool
    {
        $ext = $this->extensionOf($fileName);

        return $ext === 'docx' || str_contains($mime, 'wordprocessingml');
    }

    public function isCsvMime(string $mime, ?string $fileName = null): bool
    {
        $ext = $this->extensionOf($fileName);

        return in_array($ext, ['csv', 'tsv'], true) || str_contains($mime, 'csv') || $mime === 'text/tab-separated-values';
    }

    public function isTextViewableMime(string $mime, ?string $fileName = null): bool
    {
        if ($this->isWordDocument($mime, $fileName) || $this->isCsvMime($mime, $fileName)) {
            return true;
        }

        if (Str::startsWith($mime, 'text/')) {
            return true;
        }

        if (in_array($mime, ['application/json', 'application/xml'], true)) {
            return true;
        }

        $ext = $this->extensionOf($fileName);
        $textExtensions = [
            'txt', 'md', 'markdown', 'json', 'js', 'ts', 'tsx', 'jsx', 'css', 'scss', 'html', 'htm',
            'xml', 'yaml', 'yml', 'py', 'php', 'java', 'cs', 'c', 'cpp', 'h', 'go', 'rs', 'rb', 'sh',
            'sql', 'env', 'ini', 'log', 'vue', 'svelte',
        ];

        return in_array($ext, $textExtensions, true);
    }

    public function extensionOf(?string $fileName): string
    {
        if (! $fileName) {
            return '';
        }

        $idx = strrpos($fileName, '.');
        if ($idx === false) {
            return '';
        }

        return strtolower(substr($fileName, $idx + 1));
    }

    public function extractDocxText(string $diskPath): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($diskPath) !== true) {
            abort(422, 'Could not read Word document.');
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false || $xml === '') {
            return '';
        }

        $xml = preg_replace('/<w:tab[^>]*\/>/', "\t", $xml) ?? $xml;
        $xml = preg_replace('/<w:br[^>]*\/>/', "\n", $xml) ?? $xml;
        $xml = preg_replace('/<\/w:p>/', "\n", $xml) ?? $xml;
        $text = strip_tags($xml);

        return trim(html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }
}
