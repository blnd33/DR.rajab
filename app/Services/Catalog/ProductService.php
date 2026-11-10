<?php

namespace App\Services\Catalog;

use App\Enums\BarcodeSymbology;
use App\Enums\TrackingType;
use App\Models\BranchProductSetting;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\StockBatch;
use App\Models\StockUnit;
use App\Models\User;
use App\Support\Gs1\Gtin;
use App\Support\Locale\DigitConverter;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Catalog writes: products, their extra barcodes and per-branch minimum stock.
 * The tracking type (serial / lot) is locked once the product has stock.
 */
class ProductService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Product
    {
        $data = $this->normalize($data);
        $this->validateCodes($data);

        return DB::transaction(fn () => Product::query()->create($data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Product $product, array $data): Product
    {
        $data = $this->normalize($data);
        $this->validateCodes($data, $product);

        $tracking = $data['tracking_type'] ?? null;
        $tracking = $tracking instanceof TrackingType ? $tracking : TrackingType::tryFrom((string) $tracking);

        if ($tracking !== null && $tracking !== $product->tracking_type && $this->hasStock($product)) {
            throw ValidationException::withMessages([
                'tracking_type' => __('This product already has stock, so serial / lot tracking can no longer change.'),
            ]);
        }

        return DB::transaction(function () use ($product, $data) {
            $product->fill($data)->save();

            return $product;
        });
    }

    /**
     * "Set price" for many products at once (selling, purchase or both):
     *   set     — one amount in one currency; optionally only where the price is still empty;
     *   percent — change existing prices by a percentage (10 = 10 % more, -5 = 5 % less);
     *             products without that price are skipped and the currency stays.
     * Each changed product is saved on its own, so the catalog history shows old → new.
     *
     * @param  list<int>  $productIds
     * @param  array{price: string, mode: string, amount?: mixed, currency?: ?string, only_empty?: bool, percent?: mixed}  $data
     * @return array{changed: int, skipped: int}
     */
    public function setPrices(array $productIds, array $data, User $by): array
    {
        if (! $by->can('products.manage')) {
            throw new AuthorizationException(__('You are not allowed to change products.'));
        }

        $sides = match ($data['price'] ?? null) {
            'selling' => ['selling'],
            'purchase' => ['purchase'],
            'both' => ['selling', 'purchase'],
            default => throw ValidationException::withMessages(['price' => __('Choose which price to change.')]),
        };

        $mode = $data['mode'] ?? null;
        $amount = null;
        $factor = null;

        if ($mode === 'set') {
            $amount = $this->decimal($data['amount'] ?? null, 'amount');

            if ($amount->isNegative()) {
                throw ValidationException::withMessages(['amount' => __('The price cannot be negative.')]);
            }

            if (! in_array($data['currency'] ?? null, config('orthotrack.currencies'), true)) {
                throw ValidationException::withMessages(['currency' => __('Choose a currency.')]);
            }
        } elseif ($mode === 'percent') {
            $percent = $this->decimal($data['percent'] ?? null, 'percent');

            if ($percent->isLessThanOrEqualTo(-100) || $percent->isGreaterThan(1000) || $percent->isZero()) {
                throw ValidationException::withMessages(['percent' => __('Give a percentage between -99 and 1000, not 0.')]);
            }

            $factor = BigDecimal::one()->plus($percent->dividedBy(100, 8, RoundingMode::HalfUp));
        } else {
            throw ValidationException::withMessages(['mode' => __('Choose how to change the price.')]);
        }

        $changed = 0;
        $skipped = 0;

        DB::transaction(function () use ($productIds, $sides, $mode, $amount, $factor, $data, &$changed, &$skipped) {
            foreach ($productIds as $id) {
                $product = Product::query()->lockForUpdate()->findOrFail($id);

                foreach ($sides as $side) {
                    $current = $product->getAttribute("{$side}_price");

                    if ($mode === 'set') {
                        if (($data['only_empty'] ?? false) && $current !== null) {
                            continue;
                        }

                        $product->setAttribute("{$side}_price", (string) $amount->toScale(4, RoundingMode::HalfUp));
                        $product->setAttribute("{$side}_currency", $data['currency']);
                    } elseif ($current !== null) {
                        $product->setAttribute("{$side}_price", (string) BigDecimal::of($current)->multipliedBy($factor)->toScale(4, RoundingMode::HalfUp));
                    }
                }

                if ($product->isDirty()) {
                    $product->save();
                    $changed++;
                } else {
                    $skipped++;
                }
            }
        });

        return ['changed' => $changed, 'skipped' => $skipped];
    }

    private function decimal(mixed $value, string $field): BigDecimal
    {
        try {
            return BigDecimal::of(trim((string) DigitConverter::toWestern((string) $value)));
        } catch (MathException) {
            throw ValidationException::withMessages([$field => __('Type a number.')]);
        }
    }

    public function hasStock(Product $product): bool
    {
        return StockUnit::query()->where('product_id', $product->id)->exists()
            || StockBatch::query()->where('product_id', $product->id)->exists();
    }

    public function addBarcode(Product $product, string $barcode, BarcodeSymbology $symbology): ProductBarcode
    {
        $barcode = trim($barcode);

        if ($barcode === '') {
            throw ValidationException::withMessages(['barcode' => __('Enter the barcode.')]);
        }

        $taken = ProductBarcode::query()->where('barcode', $barcode)->first();

        if ($taken !== null) {
            throw ValidationException::withMessages(['barcode' => __('This barcode already belongs to :sku.', ['sku' => $taken->product->sku])]);
        }

        if (Product::query()->where('sku', $barcode)->whereKeyNot($product->id)->exists()) {
            throw ValidationException::withMessages(['barcode' => __('This code is the SKU of another product.')]);
        }

        return $product->barcodes()->create(['barcode' => $barcode, 'symbology' => $symbology]);
    }

    /**
     * "Learn on first scan": a box barcode (GTIN) nobody knew yet is linked to the product the
     * user picked — as its GTIN when it has none, otherwise as an extra barcode. Next time the
     * box scans by itself.
     *
     * @return 'gtin'|'barcode' where it was stored
     */
    public function learnBarcode(Product $product, string $gtin, User $by): string
    {
        $gtin = trim($gtin);

        if (! Gtin::isValid($gtin)) {
            throw ValidationException::withMessages(['barcode' => __('This is not a valid GTIN (8, 12, 13 or 14 digits with a correct check digit).')]);
        }

        return DB::transaction(function () use ($product, $gtin, $by) {
            $product = Product::query()->lockForUpdate()->findOrFail($product->id);
            $variants = Gtin::variants((string) Gtin::toGtin14($gtin));

            $owner = Product::query()->whereIn('gtin', $variants)->first()
                ?? ProductBarcode::query()->whereIn('barcode', $variants)->first()?->product;

            if ($owner !== null) {
                throw ValidationException::withMessages(['barcode' => $owner->id === $product->id
                    ? __('This barcode is already linked to :sku.', ['sku' => $product->sku])
                    : __('This barcode already belongs to :sku.', ['sku' => $owner->sku])]);
            }

            if (blank($product->gtin)) {
                $product->gtin = $gtin;
                $product->save();
                $storedAs = 'gtin';
            } else {
                $this->addBarcode($product, $gtin, BarcodeSymbology::Gtin);
                $storedAs = 'barcode';
            }

            activity('catalog')->performedOn($product)->causedBy($by)->event('barcode_learned')
                ->withProperties(['barcode' => $gtin, 'stored_as' => $storedAs])
                ->log('Box barcode linked to product');

            return $storedAs;
        });
    }

    /**
     * Set (or clear with null) the minimum stock of a product in one branch.
     */
    public function setBranchMinimum(Product $product, int $branchId, ?int $minStock, ?int $maxStock = null, ?int $reorderQty = null): ?BranchProductSetting
    {
        if ($minStock === null) {
            BranchProductSetting::query()->where('product_id', $product->id)->where('branch_id', $branchId)->first()?->delete();

            return null;
        }

        if ($minStock < 0 || ($maxStock !== null && $maxStock < $minStock)) {
            throw ValidationException::withMessages(['min_stock' => __('Minimum must be 0 or more, and the maximum cannot be below the minimum.')]);
        }

        return BranchProductSetting::query()->updateOrCreate(
            ['product_id' => $product->id, 'branch_id' => $branchId],
            ['min_stock' => $minStock, 'max_stock' => $maxStock, 'reorder_qty' => $reorderQty],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        foreach (['sku', 'gtin', 'size'] as $field) {
            if (array_key_exists($field, $data)) {
                $value = trim((string) $data[$field]);
                $data[$field] = $value === '' ? null : $value;
            }
        }

        foreach (['purchase', 'selling'] as $side) {
            if (array_key_exists("{$side}_price", $data) && blank($data["{$side}_price"])) {
                $data["{$side}_price"] = null;
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validateCodes(array $data, ?Product $product = null): void
    {
        $errors = [];

        if (array_key_exists('sku', $data) && blank($data['sku'])) {
            $errors['sku'] = __('The SKU is required.');
        }

        if (filled($data['gtin'] ?? null) && ! Gtin::isValid($data['gtin'])) {
            $errors['gtin'] = __('This is not a valid GTIN (8, 12, 13 or 14 digits with a correct check digit).');
        }

        if (filled($data['sku'] ?? null)
            && ProductBarcode::query()->where('barcode', $data['sku'])->when($product, fn ($q) => $q->where('product_id', '<>', $product->id))->exists()) {
            $errors['sku'] = __('This SKU is already an extra barcode of another product.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
