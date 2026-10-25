<?php

namespace App\Casts;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A calendar date (expiry, production date) stored as "YYYY-MM-DD" — never with a time,
 * so lookups like "lot ABC expiring 2027-01-31" match exactly on every database.
 *
 * @implements CastsAttributes<Carbon, string>
 */
class DateOnly implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        return $value === null ? null : Carbon::parse(substr((string) $value, 0, 10))->startOfDay();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof CarbonInterface
            ? $value->format('Y-m-d')
            : Carbon::parse(substr((string) $value, 0, 10))->format('Y-m-d');
    }
}
