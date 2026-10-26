<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * A kit or instrument on a transfer.
 */
enum TransferAssetStatus: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Pending = 'pending';
    case Received = 'received';
    case Missing = 'missing';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => __('Waiting'),
            self::Received => __('Received'),
            self::Missing => __('Missing'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Received => 'success',
            self::Missing => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Pending => 'heroicon-m-clock',
            self::Received => 'heroicon-m-check-circle',
            self::Missing => 'heroicon-m-question-mark-circle',
        };
    }
}
