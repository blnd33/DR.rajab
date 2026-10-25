<?php

namespace App\Models;

use App\Enums\BranchStatus;
use App\Models\Concerns\HasCleanTranslations;
use App\Models\Concerns\TracksAuthor;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property BranchStatus $status
 */
class Branch extends Model
{
    use HasCleanTranslations;

    /** @use HasFactory<BranchFactory> */
    use HasFactory;

    use LogsActivity;
    use TracksAuthor;

    /** @var list<string> */
    public array $translatable = ['name', 'city'];

    protected $fillable = [
        'code',
        'name',
        'city',
        'address',
        'phone',
        'manager_id',
        'default_currency',
        'is_head_office',
    ];

    protected function casts(): array
    {
        return [
            'status' => BranchStatus::class,
            'is_head_office' => 'boolean',
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Branch $branch) {
            $branch->code = mb_strtoupper(trim($branch->code));
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('branches')
            ->logOnly(['code', 'name', 'city', 'address', 'phone', 'manager_id', 'default_currency', 'is_head_office', 'status', 'close_reason'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('created_at');
    }

    /** @return BelongsTo<User, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /** @return HasMany<BranchLocation, $this> */
    public function locations(): HasMany
    {
        return $this->hasMany(BranchLocation::class);
    }

    /** @return HasMany<StockUnit, $this> */
    public function stockUnits(): HasMany
    {
        return $this->hasMany(StockUnit::class);
    }

    /** @return HasMany<StockBatch, $this> */
    public function stockBatches(): HasMany
    {
        return $this->hasMany(StockBatch::class);
    }

    /** @return BelongsTo<Currency, $this> */
    public function defaultCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'default_currency', 'code');
    }

    /**
     * Daily dropdowns only show open branches; reports use every branch.
     *
     * @param  Builder<Branch>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', BranchStatus::Active);
    }

    public function isActive(): bool
    {
        return $this->status === BranchStatus::Active;
    }

    /**
     * "DHK — Duhok" for selects.
     */
    public function label(): string
    {
        return "{$this->code} — {$this->name}";
    }
}
