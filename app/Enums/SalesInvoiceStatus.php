<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Sales invoice.
 */
enum SalesInvoiceStatus: string implements HasColor, HasIcon, HasLabel
{
    use EnumHelpers;

    case Draft = 'draft';
    case Issued = 'issued';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Voided = 'voided';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Issued => __('Issued'),
            self::PartiallyPaid => __('Partly paid'),
            self::Paid => __('Paid'),
            self::Voided => __('Voided'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Issued => 'info',
            self::PartiallyPaid => 'warning',
            self::Paid => 'success',
            self::Voided => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Draft => 'heroicon-m-pencil-square',
            self::Issued => 'heroicon-m-document-text',
            self::PartiallyPaid => 'heroicon-m-minus-circle',
            self::Paid => 'heroicon-m-check-circle',
            self::Voided => 'heroicon-m-x-mark',
        };
    }
}
