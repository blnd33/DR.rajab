<?php

namespace App\Services\Graphs;

/**
 * The chips on the Graphs page.
 */
enum GraphSection: string
{
    case Sales = 'sales';
    case Surgeries = 'surgeries';
    case Stock = 'stock';
    case Money = 'money';
    case Transfers = 'transfers';
    case Kits = 'kits';

    public function label(): string
    {
        return match ($this) {
            self::Sales => __('Sales'),
            self::Surgeries => __('Surgeries'),
            self::Stock => __('Items and stock'),
            self::Money => __('Money'),
            self::Transfers => __('Transfers'),
            self::Kits => __('Kits'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Sales => 'heroicon-o-banknotes',
            self::Surgeries => 'heroicon-o-heart',
            self::Stock => 'heroicon-o-archive-box',
            self::Money => 'heroicon-o-wallet',
            self::Transfers => 'heroicon-o-truck',
            self::Kits => 'heroicon-o-briefcase',
        };
    }
}
