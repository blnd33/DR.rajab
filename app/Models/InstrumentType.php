<?php

namespace App\Models;

use App\Models\Concerns\HasCleanTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Kind of reusable instrument (drill guide, reamer…).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property bool $is_active
 */
class InstrumentType extends Model
{
    use HasCleanTranslations;
    use LogsActivity;

    /** @var list<string> */
    public array $translatable = ['name'];

    protected $fillable = ['code', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(fn (InstrumentType $type) => $type->code = mb_strtoupper(trim($type->code)));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('instrument_types')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    /** @param Builder<InstrumentType> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @return array<int, string> */
    public static function options(): array
    {
        return static::query()->active()->orderBy('code')->get()->mapWithKeys(fn (InstrumentType $t) => [$t->id => "{$t->code} — {$t->name}"])->all();
    }
}
