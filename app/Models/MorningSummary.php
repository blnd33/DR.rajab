<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person's morning summary for one day, as it was built that morning.
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon $summary_date
 * @property array<string, mixed> $payload
 * @property ?Carbon $sent_at
 */
class MorningSummary extends Model
{
    protected $fillable = ['user_id', 'summary_date', 'payload', 'sent_at'];

    protected function casts(): array
    {
        return [
            'summary_date' => DateOnly::class,
            'payload' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
