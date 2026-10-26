<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Life of a kit checkout to a hospital.
 */
enum KitCheckoutStatus: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Out = 'out';
    case Returned = 'returned';
    case Reconciled = 'reconciled';
    case ReconciledWithIssues = 'reconciled_with_issues';

    public function getLabel(): string
    {
        return match ($this) {
            self::Out => __('Out at hospital'),
            self::Returned => __('Returned'),
            self::Reconciled => __('Reconciled'),
            self::ReconciledWithIssues => __('Reconciled with issues'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Out => 'warning',
            self::Returned => 'info',
            self::Reconciled => 'success',
            self::ReconciledWithIssues => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Out => 'heroicon-m-building-office-2',
            self::Returned => 'heroicon-m-arrow-down-tray',
            self::Reconciled => 'heroicon-m-check-circle',
            self::ReconciledWithIssues => 'heroicon-m-exclamation-triangle',
        };
    }
}
