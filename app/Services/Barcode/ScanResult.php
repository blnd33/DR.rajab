<?php

namespace App\Services\Barcode;

use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockUnit;
use Illuminate\Support\Collection;

/**
 * The meaning of one scan, for the Scan page, Receive stock and (later) surgery,
 * transfer and count screens.
 */
final class ScanResult
{
    /**
     * @param  Collection<int, StockBatch>  $batches
     * @param  Collection<int, StockUnit>  $units
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly ScanResultKind $kind,
        public readonly ParsedScan $parsed,
        public readonly ?Product $product = null,
        public readonly ?StockUnit $unit = null,
        public readonly Collection $batches = new Collection,
        public readonly Collection $units = new Collection,
        public readonly array $warnings = [],
    ) {}

    public function found(): bool
    {
        return $this->kind !== ScanResultKind::Unknown;
    }

    /**
     * Values to pre-fill a "receive stock" line from this scan.
     *
     * @return array{product_id: ?int, serial: ?string, lot: ?string, expiry_date: ?string, production_date: ?string}
     */
    public function prefill(): array
    {
        return [
            'product_id' => $this->product?->id,
            'serial' => $this->parsed->serial,
            'lot' => $this->parsed->lot,
            'expiry_date' => $this->parsed->expiryDate,
            'production_date' => $this->parsed->productionDate,
        ];
    }
}
