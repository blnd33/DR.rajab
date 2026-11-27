<?php

namespace App\Models;

use App\Enums\AgentType;
use App\Enums\AttachmentKind;
use App\Models\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A company agent kept by the super admin: distributor, direct seller, representative, partner,
 * technician or employee. Which columns are used depends on the type (AgentType::fields()).
 * Nobody here signs in; a technician's profile belongs to their technician account.
 *
 * @property int $id
 * @property AgentType $type
 * @property string $name
 * @property ?string $phone
 * @property ?string $city
 * @property ?int $distributor_id
 * @property ?int $user_id
 * @property ?string $persons
 * @property ?string $stock
 * @property ?string $value_amount
 * @property ?string $value_currency
 * @property ?string $shares_percent
 * @property ?string $profit_month_amount
 * @property ?string $profit_year_amount
 * @property ?string $profit_currency
 * @property ?string $position
 * @property ?string $salary_amount
 * @property ?string $salary_currency
 * @property ?string $mission_fee_amount
 * @property ?string $mission_fee_currency
 * @property ?string $notes
 * @property bool $is_active
 */
class CompanyAgent extends Model
{
    use LogsActivity;
    use TracksAuthor;

    protected $fillable = [
        'type',
        'name',
        'phone',
        'city',
        'user_id',
        'distributor_id',
        'persons',
        'stock',
        'value_amount',
        'value_currency',
        'shares_percent',
        'profit_month_amount',
        'profit_year_amount',
        'profit_currency',
        'position',
        'salary_amount',
        'salary_currency',
        'mission_fee_amount',
        'mission_fee_currency',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type' => AgentType::class,
            'is_active' => 'boolean',
            'value_amount' => 'decimal:4',
            'shares_percent' => 'decimal:4',
            'profit_month_amount' => 'decimal:4',
            'profit_year_amount' => 'decimal:4',
            'salary_amount' => 'decimal:4',
            'mission_fee_amount' => 'decimal:4',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('company_agents')->logFillable()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    /**
     * @param  Builder<CompanyAgent>  $query
     */
    public function scopeOfType(Builder $query, AgentType $type): void
    {
        $query->where('type', $type->value);
    }

    /**
     * A sub distributor's distributor.
     *
     * @return BelongsTo<CompanyAgent, $this>
     */
    public function distributor(): BelongsTo
    {
        return $this->belongsTo(CompanyAgent::class, 'distributor_id');
    }

    /**
     * A distributor's sub distributors.
     *
     * @return HasMany<CompanyAgent, $this>
     */
    public function subDistributors(): HasMany
    {
        return $this->hasMany(CompanyAgent::class, 'distributor_id')->where('type', AgentType::SubDistributor->value)->orderBy('name');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return MorphMany<Attachment, $this> */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * The latest CV (older ones are kept).
     *
     * @return MorphOne<Attachment, $this>
     */
    public function cv(): MorphOne
    {
        return $this->morphOne(Attachment::class, 'attachable')->where('kind', AttachmentKind::Cv->value)->latestOfMany();
    }
}
