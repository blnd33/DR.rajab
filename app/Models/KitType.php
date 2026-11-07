<?php

namespace App\Models;

use App\Enums\UsageLevel;
use App\Models\Concerns\HasCleanTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Kit type with its template of expected instruments and implant sizes (Module 18).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property ?string $description
 * @property ?UsageLevel $usage_level
 * @property bool $is_active
 */
class KitType extends Model
{
    use HasCleanTranslations;
    use LogsActivity;

    /** @var list<string> */
    public array $translatable = ['name', 'description'];

    protected $fillable = ['code', 'name', 'description', 'usage_level', 'is_active'];

    protected function casts(): array
    {
        return ['usage_level' => UsageLevel::class, 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(fn (KitType $type) => $type->code = mb_strtoupper(trim($type->code)));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('kit_types')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    /** @return HasMany<KitTemplateItem, $this> */
    public function templateItems(): HasMany
    {
        return $this->hasMany(KitTemplateItem::class)->orderBy('sort')->orderBy('id');
    }

    /** @return HasMany<Kit, $this> */
    public function kits(): HasMany
    {
        return $this->hasMany(Kit::class);
    }

    /** @param Builder<KitType> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @return array<int, string> */
    public static function options(): array
    {
        return static::query()->active()->orderBy('code')->get()->mapWithKeys(fn (KitType $t) => [$t->id => "{$t->code} — {$t->name}"])->all();
    }
}
