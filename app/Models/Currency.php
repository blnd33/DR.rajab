<?php

namespace App\Models;

use App\Models\Concerns\HasCleanTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $code
 * @property string $name
 * @property string $symbol
 * @property int $decimals
 */
class Currency extends Model
{
    use HasCleanTranslations;

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    public array $translatable = ['name'];

    protected $fillable = ['code', 'name', 'symbol', 'decimals', 'is_active', 'sort'];

    protected function casts(): array
    {
        return [
            'decimals' => 'integer',
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /** @param Builder<Currency> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('sort');
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return static::query()->active()->get()
            ->mapWithKeys(fn (Currency $c) => [$c->code => "{$c->code} — {$c->name}"])
            ->all();
    }
}
