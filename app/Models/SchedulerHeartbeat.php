<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Last run of one scheduled job (P5-6).
 *
 * @property string $name
 * @property ?Carbon $last_started_at
 * @property ?Carbon $last_finished_at
 * @property ?string $last_status
 * @property ?string $last_output
 */
class SchedulerHeartbeat extends Model
{
    public const CREATED_AT = null;

    public $incrementing = false;

    protected $primaryKey = 'name';

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_started_at' => 'datetime',
            'last_finished_at' => 'datetime',
        ];
    }
}
