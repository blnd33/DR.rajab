<?php

namespace App\Models;

use App\Enums\CashBoxType;
use App\Models\Concerns\HasCleanTranslations;
use App\Models\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A cash box or bank account of a branch (NULL branch = head office) in one currency.
 *
 * @property int $id
 * @property CashBoxType $type
 * @property string $current_balance
 * @property bool $allow_overdraft
 * @property bool $is_active
 * @property ?Carbon $opened_at
 * @property ?Carbon $closed_at
 * @property ?int $branch_id
 * @property string $currency
 * @property string $name
 * @property ?string $bank_name
 * @property ?string $account_number
 * @property ?string $iban
 */
class CashBox extends Model
{
    use HasCleanTranslations;
    use LogsActivity;
    use TracksAuthor;

    /** @var list<string> */
    public array $translatable = ['name'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => CashBoxType::class,
            'current_balance' => 'decimal:4',
            'allow_overdraft' => 'boolean',
            'is_active' => 'boolean',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('cash_boxes')->logOnly(['name', 'type', 'currency', 'bank_name', 'account_number', 'iban', 'is_active', 'allow_overdraft'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<MoneyTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(MoneyTransaction::class);
    }

    /** @return HasMany<CashBoxClosing, $this> */
    public function closings(): HasMany
    {
        return $this->hasMany(CashBoxClosing::class);
    }

    public function label(): string
    {
        return trim(($this->branch !== null ? $this->branch->code : __('Head office')).' · '.$this->name.' · '.$this->currency);
    }

    public function isCash(): bool
    {
        return $this->type === CashBoxType::Cash;
    }
}
