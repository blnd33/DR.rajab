<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\BillingStatus;
use App\Enums\Laterality;
use App\Enums\SurgeryDoctorRole;
use App\Enums\SurgeryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A logged surgery (Module 10): what was implanted, in whom, where and by whom.
 *
 * @property int $id
 * @property string $number
 * @property string $client_uuid
 * @property int $branch_id
 * @property ?int $customer_id
 * @property ?int $patient_id
 * @property ?int $surgery_type_id
 * @property Laterality $laterality
 * @property Carbon $surgery_date
 * @property ?int $kit_id
 * @property ?int $kit_checkout_id
 * @property SurgeryStatus $status
 * @property ?string $notes
 * @property ?list<string> $problems
 * @property int $created_by
 * @property ?Carbon $completed_at
 * @property ?Carbon $cancelled_at
 * @property ?string $cancel_reason
 * @property ?string $fee_amount
 * @property ?string $fee_currency
 * @property BillingStatus $billing_status
 */
class Surgery extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'laterality' => Laterality::class,
            'surgery_date' => DateOnly::class,
            'status' => SurgeryStatus::class,
            'problems' => 'array',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'fee_amount' => 'decimal:4',
            'billing_status' => BillingStatus::class,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('surgeries')
            ->logOnly(['status', 'surgery_date', 'customer_id', 'patient_id', 'surgery_type_id', 'kit_checkout_id', 'cancel_reason'])
            ->logOnlyDirty()->dontSubmitEmptyLogs();
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

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<SurgeryType, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(SurgeryType::class, 'surgery_type_id');
    }

    /** @return BelongsTo<Kit, $this> */
    public function kit(): BelongsTo
    {
        return $this->belongsTo(Kit::class);
    }

    /** @return BelongsTo<KitCheckout, $this> */
    public function kitCheckout(): BelongsTo
    {
        return $this->belongsTo(KitCheckout::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsToMany<User, $this> */
    public function doctors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'surgery_doctors')->withPivot('role');
    }

    /** @return HasMany<SurgeryItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SurgeryItem::class);
    }

    /** @return HasMany<SurgeryItem, $this> */
    public function liveItems(): HasMany
    {
        return $this->hasMany(SurgeryItem::class)->whereNull('reversed_at');
    }

    /** @return MorphMany<Attachment, $this> */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function leadDoctor(): ?User
    {
        return $this->doctors->first(fn (User $u) => $u->getRelationValue('pivot')?->getAttribute('role') === SurgeryDoctorRole::Lead->value)
            ?? $this->doctors->first();
    }

    public function isDoctor(User $user): bool
    {
        return $this->doctors->contains('id', $user->id) || $this->created_by === $user->id;
    }
}
