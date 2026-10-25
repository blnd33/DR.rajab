<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasLabel;

/**
 * The two front-ends of the application.
 */
enum Panel: string implements HasLabel
{
    use EnumHelpers;

    case Admin = 'admin';
    case Ops = 'ops';

    public function getLabel(): string
    {
        return match ($this) {
            self::Admin => __('Admin panel'),
            self::Ops => __('Operations app'),
        };
    }

    public function homeUrl(): string
    {
        return match ($this) {
            self::Admin => url('/admin'),
            self::Ops => route('ops.home'),
        };
    }
}
