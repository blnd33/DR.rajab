<?php

namespace App\Models;

use App\Enums\DocumentSection;
use App\Models\Concerns\HasCleanTranslations;
use App\Models\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A folder of company documents, shown as an entry in its side-menu section
 * (e.g. Catalogues → Lima, Shipment documents → AWB). Hidden instead of deleted.
 *
 * @property int $id
 * @property DocumentSection $section
 * @property string $name
 * @property int $sort
 * @property bool $is_active
 */
class DocumentFolder extends Model
{
    use HasCleanTranslations;
    use LogsActivity;
    use TracksAuthor;

    /** @var list<string> */
    public array $translatable = ['name'];

    protected $fillable = ['section', 'name', 'sort', 'is_active'];

    protected function casts(): array
    {
        return [
            'section' => DocumentSection::class,
            'sort' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('documents')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    /**
     * @param  Builder<DocumentFolder>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @return HasMany<CompanyDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(CompanyDocument::class);
    }
}
