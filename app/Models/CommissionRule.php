<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\CommissionBasis;
use App\Enums\CommissionCalc;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Doctor commission rule (feature-flagged, open question 5).
 *
 * @property int $id
 * @property CommissionBasis $basis
 * @property CommissionCalc $calc_type
 * @property string $value
 * @property ?Carbon $valid_from
 * @property ?Carbon $valid_to
 * @property bool $is_active
 * @property ?int $doctor_id
 * @property ?int $surgery_type_id
 * @property ?int $product_id
 * @property ?int $category_id
 * @property ?string $currency
 */
class CommissionRule extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'basis' => CommissionBasis::class,
            'calc_type' => CommissionCalc::class,
            'value' => 'decimal:4',
            'valid_from' => DateOnly::class,
            'valid_to' => DateOnly::class,
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('commission_rules')->logOnly(['basis', 'calc_type', 'value', 'is_active'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    /** @return BelongsTo<User, $this> */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    /** @return BelongsTo<SurgeryType, $this> */
    public function surgeryType(): BelongsTo
    {
        return $this->belongsTo(SurgeryType::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
