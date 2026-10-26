<?php

namespace App\Models;

use App\Enums\CustomerReturnStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Items coming back from a hospital / patient / customer (Module 14).
 *
 * @property int $id
 * @property string $number
 * @property int $branch_id
 * @property ?int $customer_id
 * @property CustomerReturnStatus $status
 * @property ?Carbon $received_at
 * @property ?string $notes
 */
class CustomerReturn extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => CustomerReturnStatus::class,
            'received_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('returns')->logOnly(['status', 'notes', 'void_reason'])->logOnlyDirty()->dontSubmitEmptyLogs();
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

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /** @return HasMany<CustomerReturnItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CustomerReturnItem::class);
    }
}
