<?php

namespace App\Models;

use App\Enums\LoginFailureReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only record of every login attempt (successful or failed).
 */
class LoginAttempt extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'username_tried',
        'user_id',
        'ip_address',
        'user_agent',
        'successful',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'successful' => 'boolean',
            'failure_reason' => LoginFailureReason::class,
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
