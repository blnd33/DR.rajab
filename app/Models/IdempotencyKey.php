<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Stored response for an offline-sync request, so a replay never applies twice.
 */
class IdempotencyKey extends Model
{
    public const UPDATED_AT = null;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'user_id', 'endpoint', 'request_hash', 'response_status', 'response_body'];

    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'response_body' => 'array',
        ];
    }
}
