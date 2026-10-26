<?php

namespace App\Models;

use App\Models\Concerns\HasCleanTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Configurable, translatable list of surgery types (Module 10 / 29).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property ?int $default_kit_type_id
 * @property bool $is_active
 * @property int $sort
 */
class SurgeryType extends Model
{
    use HasCleanTranslations;
    use LogsActivity;

    /** @var list<string> */
    public array $translatable = ['name'];

    protected $fillable = ['code', 'name', 'default_kit_type_id', 'is_active', 'sort'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(fn (SurgeryType $type) => $type->code = mb_strtoupper(trim($type->code)));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('surgery_types')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    /** @return BelongsTo<KitType, $this> */
    public function defaultKitType(): BelongsTo
    {
        return $this->belongsTo(KitType::class, 'default_kit_type_id');
    }

    /** @param Builder<SurgeryType> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @return array<int, string> */
    public static function options(): array
    {
        return static::query()->active()->orderBy('sort')->orderBy('code')->get()->mapWithKeys(fn (SurgeryType $t) => [$t->id => $t->name])->all();
    }
}
