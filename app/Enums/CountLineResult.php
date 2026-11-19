<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Expected vs found for one count line.
 */
enum CountLineResult: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Found = 'found';
    case Missing = 'missing';
    case ExtraOtherBranch = 'extra_other_branch';
    case ExtraUnknown = 'extra_unknown';
    case QtyMismatch = 'qty_mismatch';

    public function getLabel(): string
    {
        return match ($this) {
            self::Found => __('Found'),
            self::Missing => __('Missing'),
            self::ExtraOtherBranch => __('Belongs to another station'),
            self::ExtraUnknown => __('Not in the system'),
            self::QtyMismatch => __('Quantity differs'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Found => 'success',
            self::Missing => 'danger',
            self::ExtraOtherBranch => 'warning',
            self::ExtraUnknown => 'warning',
            self::QtyMismatch => 'warning',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Found => 'heroicon-m-check-circle',
            self::Missing => 'heroicon-m-question-mark-circle',
            self::ExtraOtherBranch => 'heroicon-m-arrows-right-left',
            self::ExtraUnknown => 'heroicon-m-plus-circle',
            self::QtyMismatch => 'heroicon-m-scale',
        };
    }
}
