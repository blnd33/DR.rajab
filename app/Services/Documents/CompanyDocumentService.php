<?php

namespace App\Services\Documents;

use App\Enums\AttachmentKind;
use App\Enums\DocumentSection;
use App\Models\CompanyDocument;
use App\Models\DocumentFolder;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Company documents in their folders (Catalogues, Official, Supplier, Shipment, Finance).
 * Upload, edit the details, add files, archive with a reason. Nothing is deleted.
 */
class CompanyDocumentService
{
    public const MAX_KB = 51200;

    public const FILE_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'text/csv',
        'application/zip',
    ];

    /**
     * @param  array<string, mixed>  $data
     * @param  list<UploadedFile>  $files
     */
    public function upload(DocumentFolder $folder, array $data, array $files, User $by): CompanyDocument
    {
        $this->authorize($by, 'documents.upload');

        if ($files === []) {
            throw ValidationException::withMessages(['files' => __('Add at least one file.')]);
        }

        return DB::transaction(function () use ($folder, $data, $files, $by) {
            $document = $folder->documents()->create($this->clean($data));
            $this->attach($document, $files, $by);

            return $document;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<UploadedFile>  $files  added next to the existing ones
     */
    public function update(CompanyDocument $document, array $data, User $by, array $files = []): CompanyDocument
    {
        $this->authorize($by, 'documents.upload');
        $this->assertNotArchived($document);

        return DB::transaction(function () use ($document, $data, $files, $by) {
            $document->update($this->clean($data));
            $this->attach($document, $files, $by);

            return $document->refresh();
        });
    }

    public function archive(CompanyDocument $document, string $reason, User $by): CompanyDocument
    {
        $this->authorize($by, 'documents.upload');
        $this->assertNotArchived($document);

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('Write why this document is archived.')]);
        }

        $document->update(['archived_at' => now(), 'archived_by' => $by->id, 'archive_reason' => trim($reason)]);

        return $document->refresh();
    }

    public function restore(CompanyDocument $document, User $by): CompanyDocument
    {
        $this->authorize($by, 'documents.upload');
        $document->update(['archived_at' => null, 'archived_by' => null, 'archive_reason' => null]);

        return $document->refresh();
    }

    /**
     * @param  array{section?: mixed, name?: mixed, sort?: mixed, is_active?: mixed}  $data
     */
    public function saveFolder(?DocumentFolder $folder, array $data, User $by): DocumentFolder
    {
        $this->authorize($by, 'documents.folders');

        $section = DocumentSection::tryFrom((string) ($data['section'] ?? $folder?->section->value ?? ''))
            ?? throw ValidationException::withMessages(['section' => __('Choose the menu section.')]);
        $name = array_filter((array) ($data['name'] ?? []), fn ($v) => is_string($v) && trim($v) !== '');

        if (blank($name['en'] ?? null)) {
            throw ValidationException::withMessages(['name' => __('Enter the name in English.')]);
        }

        $folder ??= new DocumentFolder;
        $folder->fill([
            'section' => $section,
            'name' => array_map('trim', $name),
            'sort' => (int) ($data['sort'] ?? $folder->sort ?? (DocumentFolder::query()->where('section', $section->value)->max('sort') + 1)),
            'is_active' => (bool) ($data['is_active'] ?? $folder->is_active ?? true),
        ])->save();

        return $folder;
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    private function attach(CompanyDocument $document, array $files, User $by): void
    {
        foreach ($files as $file) {
            if (! in_array($file->getMimeType(), self::FILE_TYPES, true)) {
                throw ValidationException::withMessages(['files' => __('":name" is not a PDF, Word, Excel, photo or zip file.', ['name' => $file->getClientOriginalName()])]);
            }

            $path = $file->store('documents/'.$document->document_folder_id.'/'.$document->id, 'local');

            $document->attachments()->create([
                'kind' => AttachmentKind::Document,
                'disk' => 'local',
                'path' => (string) $path,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 191),
                'mime' => $file->getMimeType(),
                'size_bytes' => (int) $file->getSize(),
                'uploaded_by' => $by->id,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function clean(array $data): array
    {
        $text = fn (string $key, int $max) => filled($data[$key] ?? null) ? mb_substr(trim((string) $data[$key]), 0, $max) : null;
        $title = $text('title', 191) ?? throw ValidationException::withMessages(['title' => __('Enter a title.')]);
        $date = $this->date($data['document_date'] ?? null, 'document_date');
        $expires = $this->date($data['expires_on'] ?? null, 'expires_on');

        if ($date !== null && $expires !== null && $expires->lt($date)) {
            throw ValidationException::withMessages(['expires_on' => __('The expiry date cannot be before the document date.')]);
        }

        return [
            'title' => $title,
            'reference' => $text('reference', 120),
            'document_date' => $date?->toDateString(),
            'expires_on' => $expires?->toDateString(),
            'notes' => $text('notes', 5000),
        ];
    }

    private function date(mixed $value, string $field): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => __('Enter a valid date.')]);
        }
    }

    private function assertNotArchived(CompanyDocument $document): void
    {
        if ($document->isArchived()) {
            throw new AuthorizationException(__('This document is archived. Bring it back first.'));
        }
    }

    private function authorize(User $by, string $permission): void
    {
        if (! $by->can($permission)) {
            throw new AuthorizationException(__('You are not allowed to do this.'));
        }
    }
}
