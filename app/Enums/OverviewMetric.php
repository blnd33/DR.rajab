<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasLabel;

/**
 * The second row of big buttons on the Main page. Each counts something per region,
 * governorate and specialty, within the stations the user may see for its permission.
 */
enum OverviewMetric: string implements HasLabel
{
    use EnumHelpers;

    case Stations = 'stations';
    case Stock = 'stock';
    case Value = 'value';
    case Sales = 'sales';
    case Profit = 'profit';

    public function getLabel(): string
    {
        return match ($this) {
            self::Stations => __('Stations'),
            self::Stock => __('Stock'),
            self::Value => __('Value'),
            self::Sales => __('Sales'),
            self::Profit => __('Profit'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Stations => 'heroicon-o-building-office-2',
            self::Stock => 'heroicon-o-archive-box',
            self::Value => 'heroicon-o-banknotes',
            self::Sales => 'heroicon-o-shopping-cart',
            self::Profit => 'heroicon-o-arrow-trending-up',
        };
    }

    /**
     * The permission (and so the stations) this button is limited to.
     */
    public function permission(): string
    {
        return match ($this) {
            self::Stations, self::Stock => 'stock.view',
            self::Value => 'stock.view_cost',
            self::Sales => 'reports.view',
            self::Profit => 'reports.profit',
        };
    }

    public function isMoney(): bool
    {
        return in_array($this, [self::Value, self::Sales, self::Profit], true);
    }

    /**
     * Sales and profit happen over time; the others are counted as of now.
     */
    public function usesPeriod(): bool
    {
        return in_array($this, [self::Sales, self::Profit], true);
    }
}
