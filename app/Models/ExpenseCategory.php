<?php

namespace App\Models;

use App\Models\Concerns\HasCleanTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Editable, translatable expense category (Module 21).
 *
 * @property int $id
 * @property bool $is_active
 * @property int $sort
 * @property string $code
 * @property string $name
 * @property ?int $parent_id
 */
class ExpenseCategory extends Model
{
    use HasCleanTranslations;
    use LogsActivity;

    /** @var list<string> */
    public array $translatable = ['name'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('expense_categories')->logOnly(['code', 'name', 'is_active'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    /** @return BelongsTo<ExpenseCategory, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'parent_id');
    }

    /** @param Builder<ExpenseCategory> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @return array<int, string> */
    public static function options(): array
    {
        return static::query()->active()->orderBy('sort')->orderBy('code')->get()->mapWithKeys(fn (ExpenseCategory $c) => [$c->id => $c->name])->all();
    }
}
