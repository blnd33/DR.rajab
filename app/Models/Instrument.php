<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\InstrumentStatus;
use App\Models\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Reusable instrument (not sold), optionally part of a kit (Module 18).
 *
 * @property int $id
 * @property string $code
 * @property string $barcode
 * @property int $instrument_type_id
 * @property ?string $manufacturer_serial
 * @property int $branch_id
 * @property ?int $kit_id
 * @property InstrumentStatus $status
 * @property ?int $holder_user_id
 * @property ?int $customer_id
 * @property ?string $purchase_cost
 * @property ?string $purchase_currency
 * @property ?Carbon $purchased_at
 * @property ?int $supplier_id
 * @property ?string $notes
 */
class Instrument extends Model
{
    use LogsActivity;
    use TracksAuthor;

    protected $fillable = [
        'code', 'barcode', 'instrument_type_id', 'manufacturer_serial', 'branch_id', 'holder_user_id',
        'purchase_cost', 'purchase_currency', 'purchased_at', 'supplier_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => InstrumentStatus::class,
            'purchase_cost' => 'decimal:4',
            'purchased_at' => DateOnly::class,
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Instrument $instrument) {
            $instrument->code = mb_strtoupper(trim($instrument->code));
            $instrument->barcode = trim($instrument->barcode ?? '') !== '' ? trim($instrument->barcode) : $instrument->code;
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('instruments')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function activityReferenceCode(): string
    {
        return $this->code;
    }

    /** @return BelongsTo<InstrumentType, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(InstrumentType::class, 'instrument_type_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Kit, $this> */
    public function kit(): BelongsTo
    {
        return $this->belongsTo(Kit::class);
    }

    /** @return BelongsTo<User, $this> */
    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'holder_user_id');
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
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
