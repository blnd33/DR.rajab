<?php

namespace App\Models;

use App\Enums\CountLineResolution;
use App\Enums\StockCountScope;
use App\Enums\StockCountStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Inventory check (Module 16): expected snapshot vs what was found.
 *
 * @property int $id
 * @property string $number
 * @property int $branch_id
 * @property StockCountScope $scope
 * @property ?int $category_id
 * @property ?int $branch_location_id
 * @property StockCountStatus $status
 * @property Carbon $snapshot_at
 * @property int $started_by
 * @property Carbon $started_at
 * @property ?Carbon $submitted_at
 * @property ?Carbon $reviewed_at
 * @property ?string $notes
 */
class StockCount extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'scope' => StockCountScope::class,
            'status' => StockCountStatus::class,
            'snapshot_at' => 'datetime',
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('stock_counts')->logOnly(['status', 'notes'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function activityReferenceCode(): string
    {
        return $this->number;
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<BranchLocation, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(BranchLocation::class, 'branch_location_id');
    }

    /** @return BelongsTo<User, $this> */
    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    /** @return HasMany<StockCountLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class);
    }

    /** @return HasMany<StockCountScan, $this> */
    public function scans(): HasMany
    {
        return $this->hasMany(StockCountScan::class);
    }

    public function scopeLabel(): string
    {
        return match ($this->scope) {
            StockCountScope::Category => $this->scope->getLabel().': '.$this->category?->name,
            StockCountScope::Location => $this->scope->getLabel().': '.$this->location?->label(),
            default => $this->scope->getLabel(),
        };
    }

    public function pendingDifferences(): int
    {
        return $this->lines()->whereNotNull('result')->where('result', '<>', 'found')->where('resolution', CountLineResolution::Pending)->count();
    }
}
