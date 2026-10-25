<?php

namespace App\Models;

use App\Models\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Supplier master data. Purchases, payments and the statement arrive in Phase 4.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property ?string $country
 * @property string $default_currency
 * @property bool $is_active
 */
class Supplier extends Model
{
    use LogsActivity;
    use TracksAuthor;

    protected $fillable = [
        'code',
        'name',
        'country',
        'contact_person',
        'phone',
        'email',
        'address',
        'default_currency',
        'payment_terms_days',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'payment_terms_days' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Supplier $supplier) {
            $supplier->code = mb_strtoupper(trim($supplier->code));
            $supplier->email = filled($supplier->email) ? mb_strtolower(trim($supplier->email)) : null;
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('suppliers')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    /** @return BelongsTo<Currency, $this> */
    public function defaultCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'default_currency', 'code');
    }

    /** @return HasMany<StockUnit, $this> */
    public function stockUnits(): HasMany
    {
        return $this->hasMany(StockUnit::class);
    }

    /** @return MorphMany<Attachment, $this> */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /** @param Builder<Supplier> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function label(): string
    {
        return "{$this->code} — {$this->name}";
    }

    /**
     * @return array<int, string>
     */
    public static function options(): array
    {
        return static::query()->active()->orderBy('name')->get()
            ->mapWithKeys(fn (Supplier $s) => [$s->id => $s->label()])
            ->all();
    }
}
