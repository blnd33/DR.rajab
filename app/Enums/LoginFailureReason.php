<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasLabel;

enum LoginFailureReason: string implements HasLabel
{
    use EnumHelpers;

    case BadCredentials = 'bad_credentials';
    case Inactive = 'inactive';
    case MfaFailed = 'mfa_failed';
    case Throttled = 'throttled';
    case NoPanelAccess = 'no_panel_access';

    public function getLabel(): string
    {
        return match ($this) {
            self::BadCredentials => __('Wrong username or password'),
            self::Inactive => __('Account is deactivated'),
            self::MfaFailed => __('Two-factor code failed'),
            self::Throttled => __('Too many attempts'),
            self::NoPanelAccess => __('No access to any panel'),
        };
    }
}
