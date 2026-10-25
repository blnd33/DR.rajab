<?php

namespace App\Services\Labels;

/**
 * One printed label: the barcode (as inline SVG) plus its text lines.
 */
final class LabelItem
{
    /**
     * @param  array<string, string>  $lines  field => text (name, sku, serial…)
     */
    public function __construct(
        public readonly string $payload,
        public readonly string $svg,
        public readonly bool $twoDimensional,
        public readonly array $lines,
    ) {}
}
