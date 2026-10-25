<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasLabel;

enum LocationType: string implements HasLabel
{
    use EnumHelpers;

    case Shelf = 'shelf';
    case Kit = 'kit';
    case Hospital = 'hospital';
    case Transit = 'transit';
    case None = 'none';

    public function getLabel(): string
    {
        return match ($this) {
            self::Shelf => __('Shelf'),
            self::Kit => __('Kit'),
            self::Hospital => __('Hospital'),
            self::Transit => __('In transit'),
            self::None => __('Not in stock'),
        };
    }

    /**
     * Locations a lot bucket can be in (lots in transit live on the transfer).
     *
     * @return list<string>
     */
    public static function batchValues(): array
    {
        return [self::Shelf->value, self::Kit->value, self::Hospital->value];
    }
}
