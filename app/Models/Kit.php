<?php

namespace App\Models;

use App\Enums\KitStatus;
use App\Models\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A physical kit (Module 18). Its contents are derived from stock_units / stock_batches /
 * instruments pointing at it, so there is no duplicate state.
 *
 * @property int $id
 * @property string $code
 * @property string $barcode
 * @property int $kit_type_id
 * @property int $home_branch_id
 * @property int $branch_id
 * @property KitStatus $status
 * @property ?int $customer_id
 * @property ?int $current_checkout_id
 * @property ?string $photo_path
 * @property ?string $notes
 * @property ?Carbon $last_prepared_at
 */
class Kit extends Model
{
    use LogsActivity;
    use TracksAuthor;

    protected $fillable = ['code', 'barcode', 'kit_type_id', 'home_branch_id', 'branch_id', 'photo_path', 'notes'];

    protected function casts(): array
    {
        return [
            'status' => KitStatus::class,
            'last_prepared_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Kit $kit) {
            $kit->code = mb_strtoupper(trim($kit->code));
            $kit->barcode = trim($kit->barcode ?? '') !== '' ? trim($kit->barcode) : $kit->code;
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('kits')->logOnly(['code', 'barcode', 'kit_type_id', 'home_branch_id', 'notes'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function activityReferenceCode(): string
    {
        return $this->code;
    }

    /** @return BelongsTo<KitType, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(KitType::class, 'kit_type_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function homeBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'home_branch_id');
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<KitCheckout, $this> */
    public function currentCheckout(): BelongsTo
    {
        return $this->belongsTo(KitCheckout::class, 'current_checkout_id');
    }

    /** @return HasMany<StockUnit, $this> */
    public function units(): HasMany
    {
        return $this->hasMany(StockUnit::class);
    }

    /** @return HasMany<StockBatch, $this> */
    public function batches(): HasMany
    {
        return $this->hasMany(StockBatch::class)->where('quantity', '>', 0);
    }

    /** @return HasMany<Instrument, $this> */
    public function instruments(): HasMany
    {
        return $this->hasMany(Instrument::class);
    }

    /** @return HasMany<KitCheckout, $this> */
    public function checkouts(): HasMany
    {
        return $this->hasMany(KitCheckout::class);
    }

    public function isOverdue(): bool
    {
        return $this->status === KitStatus::OutAtHospital
            && $this->currentCheckout?->due_back_at !== null
            && $this->currentCheckout->due_back_at->isPast();
    }

    /** @return MorphMany<AssetMovement, $this> */
    public function assetMovements(): MorphMany
    {
        return $this->morphMany(AssetMovement::class, 'asset')->latest('occurred_at')->latest('id');
    }

    public function label(): string
    {
        return "{$this->code} — {$this->type->name}";
    }
}
