<?php

namespace App\Models;

use App\Models\Concerns\HasCleanTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Unit of measure (pc, box, pack).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 */
class Uom extends Model
{
    use HasCleanTranslations;

    /** @var list<string> */
    public array $translatable = ['name'];

    protected $fillable = ['code', 'name'];

    protected static function booted(): void
    {
        static::saving(function (Uom $uom) {
            $uom->code = mb_strtolower(trim($uom->code));
        });
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * @return array<int, string>
     */
    public static function options(): array
    {
        return static::query()->orderBy('code')->get()
            ->mapWithKeys(fn (Uom $u) => [$u->id => "{$u->code} — {$u->name}"])
            ->all();
    }
}
