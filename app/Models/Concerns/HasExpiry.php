<?php

namespace App\Models\Concerns;

use App\Models\ExpiryLevel;
use App\Services\Expiry\ExpiryClassifier;
use Illuminate\Support\Carbon;

/**
 * Days left and expiry level for stock_units / stock_batches (expiry_date column).
 */
trait HasExpiry
{
    /**
     * Days until expiry in the business timezone; null when the item has no expiry date.
     */
    public function daysLeft(): ?int
    {
        if ($this->expiry_date === null) {
            return null;
        }

        $today = Carbon::now(config('orthotrack.timezone'))->startOfDay();
        $expiry = Carbon::parse($this->expiry_date->format('Y-m-d'), config('orthotrack.timezone'))->startOfDay();

        return (int) $today->diffInDays($expiry, false);
    }

    public function expiryLevel(): ?ExpiryLevel
    {
        $days = $this->daysLeft();

        return $days === null ? null : app(ExpiryClassifier::class)->levelFor($days);
    }

    public function isExpired(): bool
    {
        $days = $this->daysLeft();

        return $days !== null && $days <= 0;
    }

    /**
     * Expired, or in a level the Super Admin marked as "blocks usage".
     */
    public function isBlockedByExpiry(): bool
    {
        return $this->isExpired() || ($this->expiryLevel()->blocks_usage ?? false);
    }
}
