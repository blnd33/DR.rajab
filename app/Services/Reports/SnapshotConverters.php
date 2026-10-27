<?php

namespace App\Services\Reports;

use App\Models\FxSnapshot;
use App\Services\Money\FxService;
use App\Support\Money\FxConverter;
use Brick\Math\BigDecimal;

/**
 * Converts amounts with the snapshot each row was booked with (so a report never moves when
 * rates change); rows without a snapshot use today's rates. One converter per snapshot.
 */
final class SnapshotConverters
{
    /** @var array<int, FxConverter|null> */
    private array $cache = [];

    private ?FxConverter $current = null;

    private bool $currentLoaded = false;

    public function __construct(private readonly FxService $fx) {}

    /**
     * The amount in $to, or null when it can't be converted (no rates yet).
     */
    public function convert(string|int|float|null $amount, ?string $from, string $to, ?int $snapshotId, ?ReportContext $context = null): ?BigDecimal
    {
        if ($amount === null || $amount === '') {
            return BigDecimal::zero();
        }

        $from ??= $to;

        if ($from === $to) {
            return BigDecimal::of((string) $amount);
        }

        $converter = $this->converter($snapshotId);

        if ($converter === null) {
            if ($context !== null) {
                $context->missingRates = true;
            }

            return null;
        }

        return BigDecimal::of($converter->convert((string) $amount, $from, $to));
    }

    private function converter(?int $snapshotId): ?FxConverter
    {
        if ($snapshotId !== null) {
            if (! array_key_exists($snapshotId, $this->cache)) {
                $snapshot = FxSnapshot::query()->find($snapshotId);
                $this->cache[$snapshotId] = $snapshot !== null ? FxConverter::with($snapshot) : null;
            }

            if ($this->cache[$snapshotId] !== null) {
                return $this->cache[$snapshotId];
            }
        }

        if (! $this->currentLoaded) {
            $snapshot = $this->fx->current();
            $this->current = $snapshot !== null ? FxConverter::with($snapshot) : null;
            $this->currentLoaded = true;
        }

        return $this->current;
    }
}
