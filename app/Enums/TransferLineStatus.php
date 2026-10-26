<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Result of one transfer line at the receiving branch.
 */
enum TransferLineStatus: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Pending = 'pending';
    case Received = 'received';
    case Short = 'short';
    case Missing = 'missing';
    case Extra = 'extra';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => __('Waiting'),
            self::Received => __('Received'),
            self::Short => __('Short'),
            self::Missing => __('Missing'),
            self::Extra => __('Extra (not on the slip)'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Received => 'success',
            self::Short => 'warning',
            self::Missing => 'danger',
            self::Extra => 'warning',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Pending => 'heroicon-m-clock',
            self::Received => 'heroicon-m-check-circle',
            self::Short => 'heroicon-m-minus-circle',
            self::Missing => 'heroicon-m-question-mark-circle',
            self::Extra => 'heroicon-m-plus-circle',
        };
    }
}
