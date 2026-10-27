<?php

namespace App\Services\Reports;

/**
 * One column of a report: how to show it on screen, in Excel and on paper.
 */
final class ReportColumn
{
    public const TEXT = 'text';

    public const CODE = 'code';

    public const NUMBER = 'number';

    public const MONEY = 'money';

    public const PERCENT = 'percent';

    public const DATE = 'date';

    private function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $type,
        public readonly bool $total = false,
        public readonly bool $profit = false,
        public readonly int $decimals = 0,
        public readonly ?string $currencyKey = null,
    ) {}

    public static function text(string $name, string $label): self
    {
        return new self($name, $label, self::TEXT);
    }

    public static function code(string $name, string $label): self
    {
        return new self($name, $label, self::CODE);
    }

    public static function date(string $name, string $label): self
    {
        return new self($name, $label, self::DATE);
    }

    public static function number(string $name, string $label, bool $total = true): self
    {
        return new self($name, $label, self::NUMBER, $total);
    }

    /**
     * Money in the report's display currency.
     */
    public static function money(string $name, string $label, bool $total = true, bool $profit = false): self
    {
        return new self($name, $label, self::MONEY, $total, $profit, 2);
    }

    /**
     * Money in the currency held in another field of the row (no total: currencies differ).
     */
    public static function moneyIn(string $name, string $label, string $currencyKey): self
    {
        return new self($name, $label, self::MONEY, false, false, 2, $currencyKey);
    }

    public static function percent(string $name, string $label, bool $profit = false): self
    {
        return new self($name, $label, self::PERCENT, false, $profit, 1);
    }

    public function isNumeric(): bool
    {
        return in_array($this->type, [self::NUMBER, self::MONEY, self::PERCENT], true);
    }
}
