<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * The three Excel templates of Module 23.
 */
enum ImportType: string implements HasIcon, HasLabel
{
    use EnumHelpers;

    case Products = 'products';
    case Stock = 'stock';
    case MinStock = 'min_stock';

    public function getLabel(): string
    {
        return match ($this) {
            self::Products => __('Product catalog'),
            self::Stock => __('Stock (serials and lots)'),
            self::MinStock => __('Minimum stock per branch'),
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Products => 'heroicon-o-cube',
            self::Stock => 'heroicon-o-archive-box',
            self::MinStock => 'heroicon-o-arrow-trending-down',
        };
    }

    /**
     * Permission needed to run this import.
     */
    public function permission(): string
    {
        return match ($this) {
            self::Products => 'products.import',
            self::Stock => 'stock.import',
            self::MinStock => 'stock.min_levels',
        };
    }

    /**
     * True when rows carry a branch (and the branch scope applies per row).
     */
    public function isBranchScoped(): bool
    {
        return $this !== self::Products;
    }
}
