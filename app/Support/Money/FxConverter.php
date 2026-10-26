<?php

namespace App\Support\Money;

use App\Models\FxSnapshot;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Converts amounts between USD, EUR and IQD with ONE rate set (fx_snapshots row), so a
 * document always converts the same way, whatever the rates are today (brief §3.3).
 * USD is the pivot: IQD ↔ USD through usd_iqd, EUR ↔ USD through eur_usd.
 */
final class FxConverter
{
    private const SCALE = 12;

    public function __construct(private readonly FxSnapshot $snapshot) {}

    public static function with(FxSnapshot $snapshot): self
    {
        return new self($snapshot);
    }

    /**
     * Amount in $from → amount in $to (scale 4, half up).
     */
    public function convert(string|int|float $amount, string $from, string $to): string
    {
        $value = BigDecimal::of((string) $amount);

        if ($from !== $to) {
            $value = $this->fromUsd($this->usd($value, $from), $to);
        }

        return (string) $value->toScale(4, RoundingMode::HalfUp);
    }

    public function toUsd(string|int|float $amount, string $currency): string
    {
        return $this->convert($amount, $currency, 'USD');
    }

    /**
     * Reference rate: 1 $from = rate $to (scale 8).
     */
    public function rate(string $from, string $to): string
    {
        return (string) $this->fromUsd($this->usd(BigDecimal::one(), $from), $to)->toScale(8, RoundingMode::HalfUp);
    }

    private function usd(BigDecimal $value, string $currency): BigDecimal
    {
        return match ($currency) {
            'USD' => $value,
            'EUR' => $value->multipliedBy((string) $this->snapshot->eur_usd),
            'IQD' => $value->dividedBy((string) $this->snapshot->usd_iqd, self::SCALE, RoundingMode::HalfUp),
            default => throw new InvalidArgumentException("Unsupported currency {$currency}"),
        };
    }

    private function fromUsd(BigDecimal $usd, string $currency): BigDecimal
    {
        return match ($currency) {
            'USD' => $usd,
            'EUR' => $usd->dividedBy((string) $this->snapshot->eur_usd, self::SCALE, RoundingMode::HalfUp),
            'IQD' => $usd->multipliedBy((string) $this->snapshot->usd_iqd),
            default => throw new InvalidArgumentException("Unsupported currency {$currency}"),
        };
    }
}
