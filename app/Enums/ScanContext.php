<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasLabel;

/**
 * Screen a scan came from (kept with unrecognized scans).
 */
enum ScanContext: string implements HasLabel
{
    use EnumHelpers;

    case Lookup = 'lookup';
    case Receive = 'receive';
    case Surgery = 'surgery';
    case Transfer = 'transfer';
    case Count = 'count';

    public function getLabel(): string
    {
        return match ($this) {
            self::Lookup => __('Scan / look up'),
            self::Receive => __('Receive stock'),
            self::Surgery => __('Surgery'),
            self::Transfer => __('Transfer'),
            self::Count => __('Stock count'),
        };
    }
}
