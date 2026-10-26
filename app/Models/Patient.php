<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\Gender;
use App\Models\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Patient file (Module 12) — sensitive. Phone and notes are encrypted at rest;
 * phone_hash finds a patient by phone without decrypting every row.
 *
 * @property int $id
 * @property string $file_number
 * @property string $full_name
 * @property ?string $phone
 * @property ?string $phone_hash
 * @property ?Carbon $date_of_birth
 * @property ?int $birth_year
 * @property ?Gender $gender
 * @property ?string $notes
 * @property int $branch_id
 * @property ?int $primary_doctor_id
 */
class Patient extends Model
{
    use LogsActivity;
    use TracksAuthor;

    protected $fillable = [
        'file_number', 'full_name', 'phone', 'phone_hash', 'date_of_birth', 'birth_year', 'gender', 'notes', 'branch_id', 'primary_doctor_id',
    ];

    protected $hidden = ['phone_hash'];

    protected function casts(): array
    {
        return [
            'phone' => 'encrypted',
            'notes' => 'encrypted',
            'date_of_birth' => DateOnly::class,
            'birth_year' => 'integer',
            'gender' => Gender::class,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        // Never the phone or notes in clear text in the log.
        return LogOptions::defaults()->useLogName('patients')
            ->logOnly(['file_number', 'full_name', 'date_of_birth', 'birth_year', 'gender', 'branch_id', 'primary_doctor_id'])
            ->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function activityReferenceCode(): string
    {
        return $this->file_number;
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function primaryDoctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'primary_doctor_id');
    }

    /** @return HasMany<Surgery, $this> */
    public function surgeries(): HasMany
    {
        return $this->hasMany(Surgery::class);
    }

    public function age(): ?int
    {
        if ($this->date_of_birth !== null) {
            return (int) $this->date_of_birth->diffInYears(now());
        }

        return $this->birth_year !== null ? (int) now()->format('Y') - $this->birth_year : null;
    }

    public function label(): string
    {
        return "{$this->file_number} — {$this->full_name}";
    }
}
