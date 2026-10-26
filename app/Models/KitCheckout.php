<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\KitCheckoutStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A kit lent to a hospital for surgery, with a snapshot of what left and how it came back.
 *
 * @property int $id
 * @property string $number
 * @property int $kit_id
 * @property int $branch_id
 * @property int $customer_id
 * @property ?int $doctor_id
 * @property ?int $patient_id
 * @property ?Carbon $planned_surgery_date
 * @property ?Carbon $due_back_at
 * @property KitCheckoutStatus $status
 * @property int $checked_out_by
 * @property Carbon $checked_out_at
 * @property ?Carbon $returned_at
 * @property ?Carbon $reconciled_at
 * @property ?string $notes
 */
class KitCheckout extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'planned_surgery_date' => DateOnly::class,
            'due_back_at' => 'datetime',
            'status' => KitCheckoutStatus::class,
            'checked_out_at' => 'datetime',
            'returned_at' => 'datetime',
            'reconciled_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('kit_checkouts')->logOnly(['status', 'customer_id', 'doctor_id', 'due_back_at'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function activityReferenceCode(): string
    {
        return $this->number;
    }

    /** @return BelongsTo<Kit, $this> */
    public function kit(): BelongsTo
    {
        return $this->belongsTo(Kit::class);
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
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return HasMany<KitCheckoutItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(KitCheckoutItem::class);
    }

    /** @return HasMany<Surgery, $this> */
    public function surgeries(): HasMany
    {
        return $this->hasMany(Surgery::class);
    }

    public function isOverdue(): bool
    {
        return $this->status === KitCheckoutStatus::Out && $this->due_back_at !== null && $this->due_back_at->isPast();
    }

    public function label(): string
    {
        return "{$this->number} · {$this->kit->code}";
    }
}
