<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Credit note.
 */
enum CreditNoteStatus: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Open = 'open';
    case Applied = 'applied';
    case Voided = 'voided';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::Applied => __('Applied'),
            self::Voided => __('Voided'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::Applied => 'success',
            self::Voided => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Open => 'heroicon-m-clock',
            self::Applied => 'heroicon-m-check-circle',
            self::Voided => 'heroicon-m-x-mark',
        };
    }
}
