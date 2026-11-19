<?php

namespace App\Models;

use App\Enums\AttachmentKind;
use App\Models\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * An uploaded company document (one or more files) in a folder. Never deleted: archived with a
 * reason, and its files stay.
 *
 * @property int $id
 * @property int $document_folder_id
 * @property string $title
 * @property ?string $reference
 * @property ?Carbon $document_date
 * @property ?Carbon $expires_on
 * @property ?string $notes
 * @property ?Carbon $archived_at
 * @property ?int $archived_by
 * @property ?string $archive_reason
 */
class CompanyDocument extends Model
{
    use LogsActivity;
    use TracksAuthor;

    protected $fillable = [
        'document_folder_id',
        'title',
        'reference',
        'document_date',
        'expires_on',
        'notes',
        'archived_at',
        'archived_by',
        'archive_reason',
    ];

    protected function casts(): array
    {
        return [
            'document_date' => 'date',
            'expires_on' => 'date',
            'archived_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('documents')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    /**
     * @param  Builder<CompanyDocument>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_on !== null && $this->expires_on->lt(now(config('orthotrack.timezone'))->startOfDay());
    }

    /** @return BelongsTo<DocumentFolder, $this> */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(DocumentFolder::class, 'document_folder_id');
    }

    /** @return MorphMany<Attachment, $this> */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /** @return MorphMany<Attachment, $this> */
    public function files(): MorphMany
    {
        return $this->attachments()->where('kind', AttachmentKind::Document->value);
    }
}
