<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\DocumentFolder;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipStream\OperationMode;
use ZipStream\ZipStream;

class DocumentFolderZipExporter
{
    public function __construct(
        protected DocumentAccessService $access,
    ) {}

    public function export(int $businessId, User $user, DocumentFolder $folder): StreamedResponse
    {
        $this->access->assertCanView($user, $folder);

        $safeName = $this->safeZipEntryName($folder->name);
        $fileName = $safeName.'.zip';

        return response()->streamDownload(function () use ($businessId, $user, $folder, $safeName): void {
            $zip = new ZipStream(
                operationMode: OperationMode::NORMAL,
                outputName: $safeName.'.zip',
                sendHttpHeaders: false,
            );

            $this->appendFolderToZip($zip, $folder, $businessId, $user, $safeName);
            $zip->finish();
        }, $fileName, [
            'Content-Type' => 'application/zip',
        ]);
    }

    /**
     * @return array{bytes: string, filename: string}
     */
    public function buildZipBytes(int $businessId, User $user, DocumentFolder $folder): array
    {
        $this->access->assertCanView($user, $folder);

        $safeName = $this->safeZipEntryName($folder->name);

        ob_start();
        $zip = new ZipStream(
            operationMode: OperationMode::NORMAL,
            outputName: $safeName.'.zip',
            sendHttpHeaders: false,
        );
        $this->appendFolderToZip($zip, $folder, $businessId, $user, $safeName);
        $zip->finish();
        $bytes = ob_get_clean();

        return [
            'bytes' => $bytes === false ? '' : $bytes,
            'filename' => $safeName.'.zip',
        ];
    }

    protected function appendFolderToZip(
        ZipStream $zip,
        DocumentFolder $folder,
        int $businessId,
        User $user,
        string $basePath,
    ): void {
        if (! $this->access->canView($user, $folder)) {
            return;
        }

        $documents = Document::query()
            ->where('business_id', $businessId)
            ->where('folder_id', $folder->id)
            ->orderBy('title')
            ->get();

        foreach ($documents as $document) {
            if (! $this->access->canView($user, $document)) {
                continue;
            }

            $entryName = $basePath.'/'.$this->safeZipEntryName($document->file_name ?: $document->title);

            if ($document->type === 'link' && $document->url) {
                $zip->addFile($entryName.'.url', "[InternetShortcut]\r\nURL={$document->url}\r\n");

                continue;
            }

            if ($document->file_path && Storage::disk('public')->exists($document->file_path)) {
                $zip->addFileFromPath(
                    $entryName,
                    Storage::disk('public')->path($document->file_path),
                );
            }
        }

        $children = DocumentFolder::query()
            ->where('business_id', $businessId)
            ->where('parent_id', $folder->id)
            ->orderBy('name')
            ->get();

        foreach ($children as $child) {
            $childPath = $basePath.'/'.$this->safeZipEntryName($child->name);
            $this->appendFolderToZip($zip, $child, $businessId, $user, $childPath);
        }
    }

    protected function safeZipEntryName(string $name): string
    {
        $cleaned = trim(str_replace(['\\', '/', "\0"], '-', $name));

        return $cleaned !== '' ? $cleaned : 'untitled';
    }
}
