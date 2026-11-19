<?php

namespace App\Services\Insight;

/**
 * The parts of the morning summary (and the groups of Quick answers).
 */
enum FactSection: string
{
    case Day = 'day';
    case Money = 'money';
    case Kits = 'kits';
    case Stock = 'stock';
    case Attention = 'attention';

    public function label(): string
    {
        return match ($this) {
            self::Day => __('At a glance'),
            self::Money => __('Money'),
            self::Kits => __('Instrument sets'),
            self::Stock => __('Expiry and stock'),
            self::Attention => __('Needs attention'),
        };
    }
}
