<?php

namespace App\Support;

use App\Models\ExpiryLevel;
use App\Services\Expiry\ExpiryClassifier;
use App\Support\Locale\Display;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Expiry chip data: level color + label + days left (never color alone).
 */
final class ExpiryDisplay
{
    /**
     * @return array{level: ?ExpiryLevel, days: ?int, label: string, days_text: string, date: string, color: string, text_color: string}
     */
    public static function for(CarbonInterface|string|null $expiryDate): array
    {
        if (blank($expiryDate)) {
            return [
                'level' => null,
                'days' => null,
                'label' => __('No expiry'),
                'days_text' => '',
                'date' => '',
                'color' => '#E2E8F0',
                'text_color' => '#334155',
            ];
        }

        $date = Carbon::parse($expiryDate instanceof CarbonInterface ? $expiryDate->format('Y-m-d') : substr((string) $expiryDate, 0, 10), config('orthotrack.timezone'))->startOfDay();
        $days = (int) ExpiryClassifier::today()->diffInDays($date, false);
        $level = app(ExpiryClassifier::class)->levelFor($days);

        return [
            'level' => $level,
            'days' => $days,
            'label' => $level->name ?? __('Expiry'),
            'days_text' => self::daysText($days),
            'date' => Display::date($date),
            'color' => $level->color ?? '#E2E8F0',
            'text_color' => $level->text_color ?? '#334155',
        ];
    }

    public static function daysText(int $days): string
    {
        return match (true) {
            $days < 0 => trans_choice('Expired :count day ago|Expired :count days ago', abs($days), ['count' => Display::number(abs($days))]),
            $days === 0 => __('Expires today'),
            default => trans_choice(':count day left|:count days left', $days, ['count' => Display::number($days)]),
        };
    }
}
