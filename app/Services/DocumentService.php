<?php

// src/app/Services/DocumentService.php
declare(strict_types=1);

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentVersion;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class DocumentService
{
    private function disk(): string
    {
        return (string) config('documents.disk', 'local');
    }

    public function attach(Model $owner, UploadedFile $file, ?Carbon $retentionUntil = null): Document
    {
        /** @var Document $document */
        $document = $owner->documents()->create([
            'name' => $file->getClientOriginalName(),
            'disk' => $this->disk(),
            'retention_until' => $retentionUntil,
        ]);

        $this->addVersion($document, $file);

        return $document;
    }

    public function addVersion(Document $document, UploadedFile $file): DocumentVersion
    {
        $teamSegment = $document->team_id ?? 'shared';
        $path = $file->store("documents/{$teamSegment}", $document->disk);

        $next = (int) $document->versions()->max('version_number') + 1;

        return $document->versions()->create([
            'version_number' => $next,
            'path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize() ?: 0,
            'uploaded_by' => auth()->id(),
        ]);
    }

    public function prune(): int
    {
        $count = 0;
        $expired = Document::query()
            ->whereNotNull('retention_until')
            ->whereDate('retention_until', '<', today())
            ->with('versions')
            ->get();

        foreach ($expired as $document) {
            foreach ($document->versions as $version) {
                Storage::disk($document->disk)->delete($version->path);
            }
            $document->delete();
            $count++;
        }

        return $count;
    }
}
