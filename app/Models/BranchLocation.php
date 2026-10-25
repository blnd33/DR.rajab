<?php

namespace App\Models;

use App\Models\Concerns\HasCleanTranslations;
use App\Models\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Shelf / storage spot inside a branch.
 *
 * @property int $id
 * @property int $branch_id
 * @property string $code
 * @property ?string $name
 * @property bool $is_active
 */
class BranchLocation extends Model
{
    use HasCleanTranslations;
    use LogsActivity;
    use TracksAuthor;

    /** @var list<string> */
    public array $translatable = ['name'];

    protected $fillable = ['branch_id', 'code', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (BranchLocation $location) {
            $location->code = mb_strtoupper(trim($location->code));
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('branches')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @param Builder<BranchLocation> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * "A-1" or "A-1 — Fridge".
     */
    public function label(): string
    {
        return filled($this->name) ? "{$this->code} — {$this->name}" : $this->code;
    }
}
