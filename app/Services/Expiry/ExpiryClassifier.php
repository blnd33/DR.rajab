<?php

namespace App\Services\Expiry;

use App\Models\ExpiryLevel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Days left → expiry level. The level set is loaded once per request (scoped singleton).
 */
class ExpiryClassifier
{
    /** @var Collection<int, ExpiryLevel>|null */
    private ?Collection $levels = null;

    /**
     * @return Collection<int, ExpiryLevel> ordered from "Expired" to the open-ended last level
     */
    public function levels(): Collection
    {
        return $this->levels ??= ExpiryLevel::query()
            ->orderByDesc('is_expired_level')
            ->orderBy('min_days')
            ->get();
    }

    public function levelFor(int $daysLeft): ?ExpiryLevel
    {
        return $this->levels()->first(fn (ExpiryLevel $level) => $level->contains($daysLeft));
    }

    public function expiredLevel(): ?ExpiryLevel
    {
        return $this->levels()->firstWhere('is_expired_level', true);
    }

    /**
     * Items expiring on or before this date are blocked from surgery, sale and transfer:
     * expired, plus any level the Super Admin marked "blocks usage".
     */
    public function blockedThrough(): Carbon
    {
        $days = (int) $this->levels()
            ->where('blocks_usage', true)
            ->max(fn (ExpiryLevel $level) => $level->max_days ?? 0);

        return self::today()->addDays(max(0, $days));
    }

    /**
     * expiry_date range [from, to] (inclusive, nullable = open) for a level, relative to today.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public function dateRange(ExpiryLevel $level): array
    {
        $today = self::today();

        return [
            $level->min_days === null ? null : $today->copy()->addDays($level->min_days)->toDateString(),
            $level->max_days === null ? null : $today->copy()->addDays($level->max_days)->toDateString(),
        ];
    }

    public static function today(): Carbon
    {
        return Carbon::now(config('orthotrack.timezone'))->startOfDay();
    }

    public function flush(): void
    {
        $this->levels = null;
    }
}
