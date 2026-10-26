<?php

namespace App\Models;

use App\Enums\SupplierReturnStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Items going back to a supplier (Module 14). Credit note + balance: Phase 4.
 *
 * @property int $id
 * @property string $number
 * @property int $supplier_id
 * @property int $branch_id
 * @property SupplierReturnStatus $status
 * @property ?string $currency
 * @property string $total_credit
 * @property ?Carbon $sent_at
 * @property ?string $notes
 */
class SupplierReturn extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => SupplierReturnStatus::class,
            'total_credit' => 'decimal:4',
            'sent_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('returns')->logOnly(['status', 'notes', 'total_credit', 'void_reason'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function activityReferenceCode(): string
    {
        return $this->number;
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<SupplierReturnItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SupplierReturnItem::class);
    }
}
