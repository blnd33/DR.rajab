<?php

namespace App\Services\Barcode;

/**
 * What a parser read from one scan. Every field is optional; ScanResolver decides
 * what it means (an existing unit, a new unit to receive, a product, a lot…).
 */
final class ParsedScan
{
    /**
     * @param  array<string, string>  $extras  other GS1 AIs (240, 30…)
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly string $parser,
        public readonly string $raw,
        public readonly ?string $gtin = null,
        public readonly ?string $sku = null,
        public readonly ?string $serial = null,
        public readonly ?string $lot = null,
        public readonly ?string $expiryDate = null,
        public readonly ?string $productionDate = null,
        public readonly ?string $code = null,
        public readonly array $extras = [],
        public readonly array $warnings = [],
    ) {}

    public function isGs1(): bool
    {
        return $this->parser === 'gs1';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'parser' => $this->parser,
            'gtin' => $this->gtin,
            'sku' => $this->sku,
            'serial' => $this->serial,
            'lot' => $this->lot,
            'expiry_date' => $this->expiryDate,
            'production_date' => $this->productionDate,
            'code' => $this->code,
            'extras' => $this->extras ?: null,
            'warnings' => $this->warnings ?: null,
        ], fn ($value) => $value !== null);
    }
}
