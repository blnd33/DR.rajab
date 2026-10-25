<?php

namespace App\Enums\Concerns;

/**
 * Shared helpers for string-backed enums that implement Filament's HasLabel.
 */
trait EnumHelpers
{
    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    /**
     * value => translated label, for selects.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->getLabel();
        }

        return $options;
    }
}
