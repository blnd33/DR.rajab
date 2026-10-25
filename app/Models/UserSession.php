<?php

namespace App\Models;

use App\Enums\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row per logged-in device. Revoking a row logs that device out on its next request.
 *
 * @property int $id
 * @property int $user_id
 * @property ?Panel $panel
 * @property ?string $device_label
 * @property Carbon $last_activity_at
 * @property ?Carbon $revoked_at
 */
class UserSession extends Model
{
    protected $fillable = [
        'user_id',
        'session_id_hash',
        'panel',
        'device_label',
        'user_agent',
        'ip_address',
        'last_activity_at',
    ];

    protected $hidden = ['session_id_hash'];

    protected function casts(): array
    {
        return [
            'panel' => Panel::class,
            'last_activity_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /** @param Builder<UserSession> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
