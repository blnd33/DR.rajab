<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * What a stock count covers (Module 16).
 */
enum StockCountScope: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Full = 'full';
    case Category = 'category';
    case Location = 'location';

    public function getLabel(): string
    {
        return match ($this) {
            self::Full => __('Whole station'),
            self::Category => __('One category'),
            self::Location => __('One shelf'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Full => 'primary',
            self::Category => 'info',
            self::Location => 'info',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Full => 'heroicon-m-building-office',
            self::Category => 'heroicon-m-folder',
            self::Location => 'heroicon-m-inbox-stack',
        };
    }
}
