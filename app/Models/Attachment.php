<?php

namespace App\Models;

use App\Enums\AttachmentKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

/**
 * File attached to any record (invoice PDF, receipt, photo, implant sticker…).
 */
class Attachment extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'attachable_type',
        'attachable_id',
        'kind',
        'disk',
        'path',
        'original_name',
        'mime',
        'size_bytes',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'kind' => AttachmentKind::class,
            'size_bytes' => 'integer',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function temporaryUrl(int $minutes = 10): string
    {
        $disk = Storage::disk($this->disk);

        return $disk->providesTemporaryUrls()
            ? $disk->temporaryUrl($this->path, now()->addMinutes($minutes))
            : $disk->url($this->path);
    }
}
