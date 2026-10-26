<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Reconciliation result of a kit checkout line.
 */
enum CheckoutItemOutcome: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Pending = 'pending';
    case Returned = 'returned';
    case UsedInSurgery = 'used_in_surgery';
    case Unaccounted = 'unaccounted';
    case Damaged = 'damaged';
    case Missing = 'missing';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => __('Not checked yet'),
            self::Returned => __('Returned'),
            self::UsedInSurgery => __('Used in surgery'),
            self::Unaccounted => __('Unaccounted'),
            self::Damaged => __('Damaged'),
            self::Missing => __('Missing'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Returned => 'success',
            self::UsedInSurgery => 'primary',
            self::Unaccounted => 'danger',
            self::Damaged => 'danger',
            self::Missing => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Pending => 'heroicon-m-clock',
            self::Returned => 'heroicon-m-check-circle',
            self::UsedInSurgery => 'heroicon-m-heart',
            self::Unaccounted => 'heroicon-m-question-mark-circle',
            self::Damaged => 'heroicon-m-exclamation-triangle',
            self::Missing => 'heroicon-m-question-mark-circle',
        };
    }
}
