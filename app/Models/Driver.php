<?php

namespace App\Models;

use App\Enums\PlateKind;
use App\Enums\PlateRegion;
use App\Models\Concerns\TracksAuthor;
use App\Support\Plate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A driver who brings goods for the company, with the car and its Iraqi plate. What we pay is
 * recorded per trip (DriverTrip).
 *
 * @property int $id
 * @property string $name
 * @property ?string $phone
 * @property ?string $car_type
 * @property bool $company_car
 * @property PlateKind $plate_kind
 * @property ?string $plate_code
 * @property ?string $plate_letter
 * @property string $plate_number
 * @property ?PlateRegion $plate_region
 * @property ?string $notes
 * @property bool $is_active
 * @property-read string $plate
 */
class Driver extends Model
{
    use LogsActivity;
    use TracksAuthor;

    protected $fillable = [
        'name',
        'phone',
        'car_type',
        'company_car',
        'plate_kind',
        'plate_code',
        'plate_letter',
        'plate_number',
        'plate_region',
        'notes',
        'is_active',
    ];

    protected $appends = ['plate'];

    protected function casts(): array
    {
        return [
            'company_car' => 'boolean',
            'is_active' => 'boolean',
            'plate_kind' => PlateKind::class,
            'plate_region' => PlateRegion::class,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('drivers')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    /**
     * "24 A 3003" or "12345 دهوك".
     */
    public function getPlateAttribute(): string
    {
        return Plate::format($this->plate_kind, $this->plate_code, $this->plate_letter, $this->plate_number, $this->plate_region);
    }

    /** @return HasMany<DriverTrip, $this> */
    public function trips(): HasMany
    {
        return $this->hasMany(DriverTrip::class);
    }
}
