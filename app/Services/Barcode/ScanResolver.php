<?php

namespace App\Services\Barcode;

use App\Enums\ScanContext;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\StockBatch;
use App\Models\StockUnit;
use App\Models\UnrecognizedScan;
use App\Models\User;
use App\Services\Barcode\Contracts\ScanParser;
use App\Support\Gs1\Gtin;
use Illuminate\Support\Collection;

/**
 * Raw scan → ScanResult, through the parser chain in config('orthotrack.scan_parsers').
 * Codes nobody understands are kept in unrecognized_scans (raw + hex) so new formats
 * can be added when the client's real boxes arrive.
 */
class ScanResolver
{
    /**
     * @param  bool  $record  keep an unknown code in unrecognized_scans (off for typed questions)
     */
    public function resolve(string $raw, ScanContext $context = ScanContext::Lookup, ?User $user = null, ?int $branchId = null, bool $record = true): ScanResult
    {
        $parsed = $this->parse($raw);

        $result = $parsed === null
            ? new ScanResult(ScanResultKind::Unknown, new ParsedScan(parser: 'none', raw: $raw))
            : $this->interpret($parsed);

        if ($record && ! $result->found() && trim($raw) !== '') {
            UnrecognizedScan::query()->create([
                'raw' => mb_substr($raw, 0, 2000),
                'raw_hex' => bin2hex(substr($raw, 0, 1000)),
                'context' => $context,
                'branch_id' => $branchId,
                'user_id' => $user?->id,
            ]);
        }

        return $result;
    }

    public function parse(string $raw): ?ParsedScan
    {
        foreach ($this->parsers() as $parser) {
            if (($parsed = $parser->parse($raw)) !== null) {
                return $parsed;
            }
        }

        return null;
    }

    private function interpret(ParsedScan $parsed): ScanResult
    {
        return match ($parsed->parser) {
            'plain' => $this->interpretPlain($parsed),
            default => $this->interpretStructured($parsed),
        };
    }

    /**
     * GS1 or our own labels: fields are known, look them up.
     */
    private function interpretStructured(ParsedScan $parsed): ScanResult
    {
        $warnings = $parsed->warnings;
        $product = null;

        if ($parsed->gtin !== null) {
            $product = $this->productByGtin($parsed->gtin);
        } elseif ($parsed->sku !== null) {
            $product = $this->productBySku($parsed->sku);
        }

        if ($parsed->serial !== null) {
            $unit = $this->unitBySerial($parsed->serial);

            if ($unit !== null) {
                if ($product !== null && $unit->product_id !== $product->id) {
                    $warnings[] = __('This serial is registered under another product (:sku). Check the label.', ['sku' => $unit->product->sku]);
                }

                return new ScanResult(ScanResultKind::Unit, $parsed, $unit->product, $unit, warnings: $warnings);
            }

            if ($product !== null && $product->isSerialTracked()) {
                return new ScanResult(ScanResultKind::NewUnit, $parsed, $product, warnings: $warnings);
            }
        }

        if ($product === null) {
            if ($parsed->gtin !== null) {
                $warnings[] = __('No product has GTIN :gtin yet. Add it to the catalog (or as an extra barcode).', ['gtin' => $parsed->gtin]);
            }

            return new ScanResult(ScanResultKind::Unknown, $parsed, warnings: $warnings);
        }

        if (! $product->isSerialTracked()) {
            $query = StockBatch::query()->with(['branch', 'location'])->where('product_id', $product->id)->onHand();

            // A box received with its own SN: that exact box. Otherwise by lot / expiry.
            if ($parsed->serial !== null && (clone $query)->where('serial', $parsed->serial)->exists()) {
                $query->where('serial', $parsed->serial);
            } else {
                $query->when($parsed->lot !== null, fn ($q) => $q->where('lot', $parsed->lot))
                    ->when($parsed->expiryDate !== null, fn ($q) => $q->whereDate('expiry_date', $parsed->expiryDate));
            }

            $batches = $query->orderBy('expiry_date')->get();

            return new ScanResult(ScanResultKind::Lot, $parsed, $product, batches: $batches, warnings: $warnings);
        }

        if ($parsed->serial === null && $parsed->lot !== null) {
            $warnings[] = __('This label has no serial number. Scan the serial label of the unit.');
        }

        return new ScanResult(ScanResultKind::Product, $parsed, $product, warnings: $warnings);
    }

    /**
     * A plain code: serial → SKU → GTIN → extra barcode → lot number.
     */
    private function interpretPlain(ParsedScan $parsed): ScanResult
    {
        $code = (string) $parsed->code;

        if (($unit = $this->unitBySerial($code)) !== null) {
            return new ScanResult(ScanResultKind::Unit, $parsed, $unit->product, $unit);
        }

        // The SN of a lot box received with its serial.
        $boxes = StockBatch::query()->with(['product', 'branch', 'location'])->where('serial', $code)->onHand()->get();

        if ($boxes->isNotEmpty()) {
            return new ScanResult(ScanResultKind::Lot, $parsed, $boxes->first()->product, batches: $boxes);
        }

        $product = $this->productBySku($code)
            ?? ($parsed->gtin !== null ? $this->productByGtin($parsed->gtin) : null)
            ?? ProductBarcode::query()->where('barcode', $code)->first()?->product;

        if ($product !== null) {
            if (! $product->isSerialTracked()) {
                $batches = StockBatch::query()->with(['branch', 'location'])->where('product_id', $product->id)->onHand()->orderBy('expiry_date')->get();

                return new ScanResult(ScanResultKind::Lot, $parsed, $product, batches: $batches);
            }

            return new ScanResult(ScanResultKind::Product, $parsed, $product);
        }

        $units = StockUnit::query()->with(['product', 'branch'])->where('lot', $code)->limit(50)->get();
        $batches = StockBatch::query()->with(['product', 'branch', 'location'])->where('lot', $code)->onHand()->limit(50)->get();

        if ($units->isNotEmpty() || $batches->isNotEmpty()) {
            return new ScanResult(ScanResultKind::LotSearch, $parsed, batches: $batches, units: $units);
        }

        return new ScanResult(ScanResultKind::Unknown, $parsed);
    }

    private function unitBySerial(string $serial): ?StockUnit
    {
        return StockUnit::query()->with(['product', 'branch', 'location'])->where('serial', $serial)->first();
    }

    private function productBySku(string $sku): ?Product
    {
        return Product::query()->where('sku', $sku)->first()
            ?? Product::query()->whereRaw('LOWER(sku) = ?', [mb_strtolower($sku)])->first();
    }

    private function productByGtin(string $gtin): ?Product
    {
        $gtin14 = Gtin::toGtin14($gtin) ?? $gtin;
        $variants = Gtin::isValid($gtin14) ? Gtin::variants($gtin14) : [$gtin];

        return Product::query()->whereIn('gtin', $variants)->first()
            ?? ProductBarcode::query()->whereIn('barcode', $variants)->first()?->product;
    }

    /**
     * @return Collection<int, ScanParser>
     */
    private function parsers(): Collection
    {
        return collect(config('orthotrack.scan_parsers', []))->map(fn (string $class) => app($class));
    }
}
