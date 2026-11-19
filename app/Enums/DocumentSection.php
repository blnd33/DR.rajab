<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasLabel;

/**
 * The side-menu sections that hold company document folders. Finance is the existing Finance
 * menu; the others are new menu sections of their own.
 */
enum DocumentSection: string implements HasLabel
{
    use EnumHelpers;

    case Catalogues = 'catalogues';
    case Official = 'official';
    case Supplier = 'supplier';
    case Shipment = 'shipment';
    case Finance = 'finance';

    /**
     * The side-menu group label (Finance shares the existing group).
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::Catalogues => __('Catalogues'),
            self::Official => __('Official company documents'),
            self::Supplier => __('Supplier company documents'),
            self::Shipment => __('Shipment documents'),
            self::Finance => __('Finance'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Catalogues => 'heroicon-o-book-open',
            self::Official => 'heroicon-o-building-library',
            self::Supplier => 'heroicon-o-document-text',
            self::Shipment => 'heroicon-o-paper-airplane',
            self::Finance => 'heroicon-o-scale',
        };
    }
}
