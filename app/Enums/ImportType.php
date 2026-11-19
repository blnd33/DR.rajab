<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * The three Excel templates of Module 23, and a supplier's own catalog workbook.
 */
enum ImportType: string implements HasIcon, HasLabel
{
    use EnumHelpers;

    case Products = 'products';
    case Stock = 'stock';
    case MinStock = 'min_stock';
    case SupplierCatalog = 'supplier_catalog';

    public function getLabel(): string
    {
        return match ($this) {
            self::Products => __('Product catalog'),
            self::Stock => __('Stock (serials and lots)'),
            self::MinStock => __('Minimum stock per station'),
            self::SupplierCatalog => __('Supplier catalog (Lima / Enovis or Mikron workbook)'),
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Products => 'heroicon-o-cube',
            self::Stock => 'heroicon-o-archive-box',
            self::MinStock => 'heroicon-o-arrow-trending-down',
            self::SupplierCatalog => 'heroicon-o-book-open',
        };
    }

    /**
     * Permission needed to run this import.
     */
    public function permission(): string
    {
        return match ($this) {
            self::Products, self::SupplierCatalog => 'products.import',
            self::Stock => 'stock.import',
            self::MinStock => 'stock.min_levels',
        };
    }

    /**
     * True when rows carry a branch (and the branch scope applies per row).
     */
    public function isBranchScoped(): bool
    {
        return ! in_array($this, [self::Products, self::SupplierCatalog], true);
    }

    /**
     * False when the file is the supplier's own workbook (no template to download).
     */
    public function hasTemplate(): bool
    {
        return $this !== self::SupplierCatalog;
    }
}
