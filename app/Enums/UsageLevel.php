<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * How often the doctor uses an item or an instrument set — the colours of his supplier
 * workbook. Most-used items come first in searches; "not used" items are archived.
 */
enum UsageLevel: string implements HasColor, HasLabel
{
    use EnumHelpers;

    case MostUsed = 'most_used';
    case LessUsed = 'less_used';
    case Sometimes = 'sometimes';
    case Rarely = 'rarely';
    case NotUsed = 'not_used';

    public function getLabel(): string
    {
        return match ($this) {
            self::MostUsed => __('Most used'),
            self::LessUsed => __('Less used'),
            self::Sometimes => __('Sometimes used'),
            self::Rarely => __('Rarely used'),
            self::NotUsed => __('Not used'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::MostUsed => 'success',
            self::LessUsed => 'info',
            self::Sometimes => 'gray',
            self::Rarely => 'warning',
            self::NotUsed => 'danger',
        };
    }

    /**
     * Sort position: most used first, unknown after "rarely", "not used" last.
     */
    public function rank(): int
    {
        return match ($this) {
            self::MostUsed => 1,
            self::LessUsed => 2,
            self::Sometimes => 3,
            self::Rarely => 4,
            self::NotUsed => 6,
        };
    }

    /**
     * The meaning written next to a colour in the workbook's key → level. Null when the
     * text is not about usage (headings, "additional instruments"…).
     */
    public static function fromKeyText(string $text): ?self
    {
        $text = mb_strtolower(preg_replace('/\s+/u', ' ', $text) ?? '');

        return match (true) {
            str_contains($text, 'will not be ordered'), str_contains($text, 'not used'), str_contains($text, 'not produced') => self::NotUsed,
            str_contains($text, 'almost never'), str_contains($text, 'rarely') => self::Rarely,
            str_contains($text, 'sometimes') => self::Sometimes,
            str_contains($text, 'less commonly'), str_contains($text, 'less used') => self::LessUsed,
            str_contains($text, 'most commonly'), str_contains($text, 'used frequently'), str_contains($text, 'will be ordered') => self::MostUsed,
            default => null,
        };
    }

    /**
     * SQL expression ranking a usage_level column (for ORDER BY).
     */
    public static function orderSql(string $column = 'usage_level'): string
    {
        $cases = implode(' ', array_map(fn (self $level) => "WHEN '{$level->value}' THEN {$level->rank()}", self::cases()));

        return "CASE {$column} {$cases} ELSE 5 END";
    }
}
