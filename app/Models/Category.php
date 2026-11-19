<?php

namespace App\Models;

use App\Enums\Specialty;
use App\Models\Concerns\HasCleanTranslations;
use App\Models\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Product category (tree: "Implants › Knee").
 *
 * @property int $id
 * @property ?int $parent_id
 * @property ?Specialty $specialty
 * @property string $code
 * @property string $name
 * @property bool $is_active
 */
class Category extends Model
{
    use HasCleanTranslations;
    use LogsActivity;
    use TracksAuthor;

    /** @var list<string> */
    public array $translatable = ['name'];

    protected $fillable = ['parent_id', 'specialty', 'code', 'name', 'is_active', 'sort'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort' => 'integer',
            'specialty' => Specialty::class,
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Category $category) {
            $category->code = mb_strtoupper(trim($category->code));
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('catalog')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    /** @return BelongsTo<Category, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /** @return HasMany<Category, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** @param Builder<Category> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * "Implants › Knee"
     */
    public function path(): string
    {
        $names = [$this->name];
        $parent = $this->parent;
        $guard = 0;

        while ($parent !== null && $guard++ < 10) {
            array_unshift($names, $parent->name);
            $parent = $parent->parent;
        }

        return implode(' › ', $names);
    }

    /**
     * id => "Implants › Knee", for selects.
     *
     * @return array<int, string>
     */
    public static function options(): array
    {
        return static::query()->active()->with('parent.parent')->orderBy('sort')->get()
            ->mapWithKeys(fn (Category $c) => [$c->id => $c->path()])
            ->sort()
            ->all();
    }
}
