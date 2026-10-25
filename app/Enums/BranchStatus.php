<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum BranchStatus: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Active = 'active';
    case Closed = 'closed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => __('Active'),
            self::Closed => __('Closed'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Closed => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Active => 'heroicon-m-check-circle',
            self::Closed => 'heroicon-m-archive-box',
        };
    }
}
