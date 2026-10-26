<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * How urgent an issue is.
 */
enum IssueSeverity: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';

    public function getLabel(): string
    {
        return match ($this) {
            self::Info => __('Info'),
            self::Warning => __('Warning'),
            self::Critical => __('Critical'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Info => 'info',
            self::Warning => 'warning',
            self::Critical => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Info => 'heroicon-m-information-circle',
            self::Warning => 'heroicon-m-exclamation-triangle',
            self::Critical => 'heroicon-m-exclamation-circle',
        };
    }
}
