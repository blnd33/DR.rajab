<?php

namespace App\Models;

use App\Models\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Brand / manufacturer (names are proper nouns — not translated).
 *
 * @property int $id
 * @property string $name
 * @property ?string $country
 * @property bool $is_active
 */
class Brand extends Model
{
    use LogsActivity;
    use TracksAuthor;

    protected $fillable = ['name', 'country', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('catalog')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** @param Builder<Brand> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
