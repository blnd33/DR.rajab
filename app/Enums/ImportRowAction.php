<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ImportRowAction: string implements HasColor, HasLabel
{
    use EnumHelpers;

    case Create = 'create';
    case Update = 'update';
    case Unchanged = 'unchanged';
    case Error = 'error';

    public function getLabel(): string
    {
        return match ($this) {
            self::Create => __('New'),
            self::Update => __('Update'),
            self::Unchanged => __('No change'),
            self::Error => __('Error'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Create => 'success',
            self::Update => 'warning',
            self::Unchanged => 'gray',
            self::Error => 'danger',
        };
    }
}
