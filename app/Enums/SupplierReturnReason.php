<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Why items go back to the supplier.
 */
enum SupplierReturnReason: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Expired = 'expired';
    case Defective = 'defective';
    case Recall = 'recall';
    case WrongItem = 'wrong_item';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Expired => __('Expired'),
            self::Defective => __('Defective'),
            self::Recall => __('Recall'),
            self::WrongItem => __('Wrong item'),
            self::Other => __('Other'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Expired => 'danger',
            self::Defective => 'warning',
            self::Recall => 'danger',
            self::WrongItem => 'warning',
            self::Other => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Expired => 'heroicon-m-no-symbol',
            self::Defective => 'heroicon-m-wrench',
            self::Recall => 'heroicon-m-shield-exclamation',
            self::WrongItem => 'heroicon-m-arrows-right-left',
            self::Other => 'heroicon-m-ellipsis-horizontal',
        };
    }
}
