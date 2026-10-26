<?php

namespace App\Models;

use App\Enums\CustomerType;
use App\Models\Concerns\HasCleanTranslations;
use App\Models\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Hospital or other customer (Module 11).
 *
 * @property int $id
 * @property string $code
 * @property CustomerType $type
 * @property string $name
 * @property ?string $city
 * @property string $default_currency
 * @property bool $credit_enabled
 * @property ?string $credit_limit
 * @property bool $is_active
 */
class Customer extends Model
{
    use HasCleanTranslations;
    use LogsActivity;
    use TracksAuthor;

    /** @var list<string> */
    public array $translatable = ['name', 'city'];

    protected $fillable = [
        'code', 'type', 'name', 'city', 'address', 'phone', 'email', 'contact_person',
        'tax_number', 'default_currency', 'credit_enabled', 'credit_limit', 'notes', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type' => CustomerType::class,
            'credit_enabled' => 'boolean',
            'credit_limit' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Customer $customer) {
            $customer->code = mb_strtoupper(trim($customer->code));
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('customers')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    /** @param Builder<Customer> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function label(): string
    {
        return "{$this->code} — {$this->name}";
    }

    /**
     * @return array<int, string>
     */
    public static function options(): array
    {
        return static::query()->active()->orderBy('code')->get()->mapWithKeys(fn (Customer $c) => [$c->id => $c->label()])->all();
    }
}
