<?php

namespace App\Services\Stock\Data;

/**
 * One line of stock coming in (opening balance, Excel import; purchases in Phase 4).
 * Serial-tracked products: serial required, quantity 1. Lot-tracked: quantity ≥ 1.
 */
final class ReceiveLine
{
    public function __construct(
        public readonly int $productId,
        public readonly int $quantity = 1,
        public readonly ?string $serial = null,
        public readonly ?string $lot = null,
        public readonly ?string $expiryDate = null,
        public readonly ?string $productionDate = null,
        public readonly string $unitCost = '0',
        public readonly string $costCurrency = 'USD',
        public readonly ?int $supplierId = null,
        public readonly ?int $locationId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            productId: (int) $data['product_id'],
            quantity: (int) ($data['quantity'] ?? 1),
            serial: self::text($data['serial'] ?? null),
            lot: self::text($data['lot'] ?? null),
            expiryDate: self::text($data['expiry_date'] ?? null),
            productionDate: self::text($data['production_date'] ?? null),
            unitCost: filled($data['unit_cost'] ?? null) ? (string) $data['unit_cost'] : '0',
            costCurrency: (string) ($data['cost_currency'] ?? 'USD'),
            supplierId: filled($data['supplier_id'] ?? null) ? (int) $data['supplier_id'] : null,
            locationId: filled($data['location_id'] ?? null) ? (int) $data['location_id'] : null,
        );
    }

    private static function text(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
