<?php

namespace App\Services\Imports\Handlers;

use App\Enums\ImportRowAction;
use App\Enums\TrackingType;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Currency;
use App\Models\DataImport;
use App\Models\DataImportRow;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Uom;
use App\Models\User;
use App\Services\Catalog\ProductService;
use App\Services\Imports\CellParser;
use App\Services\Imports\Contracts\RowHandler;
use App\Services\Imports\RowFailed;
use App\Services\Imports\RowResult;
use App\Support\Gs1\Gtin;
use App\Support\Locale\LocaleRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Template A — product catalog. Matched by SKU: existing products are updated, new SKUs
 * created. Empty cells never overwrite existing values.
 */
class ProductRowHandler implements RowHandler
{
    /** @var array<string, int> lower(code or name in any language) → category id */
    private array $categories = [];

    /** @var array<string, int> */
    private array $brands = [];

    /** @var array<string, int> */
    private array $uoms = [];

    /** @var list<string> */
    private array $currencies = [];

    /** @var array<string, int> sku → first row number in this file */
    private array $seenSkus = [];

    /** @var array<string, string> gtin → sku, within this file */
    private array $seenGtins = [];

    public function __construct(private readonly ProductService $products) {}

    public function columns(): array
    {
        return [
            'sku' => 'SKU',
            'gtin' => 'GTIN',
            'name_en' => 'Name EN',
            'name_ar' => 'Name AR',
            'name_ckb' => 'Name KU',
            'category' => 'Category',
            'brand' => 'Brand',
            'size' => 'Size',
            'unit' => 'Unit',
            'tracking_type' => 'Tracking type',
            'purchase_price' => 'Purchase price',
            'selling_price' => 'Selling price',
            'currency' => 'Currency',
            'min_stock' => 'Min stock',
        ];
    }

    public function requiredColumns(): array
    {
        return ['sku'];
    }

    public function example(): array
    {
        return [
            'sku' => 'KN-TIB-5',
            'gtin' => '08712345678906',
            'name_en' => 'Tibial tray',
            'name_ar' => 'صينية الظنبوب',
            'name_ckb' => 'سینی قەڵەمەی قاچ',
            'category' => 'KNEE',
            'brand' => 'OrthoMed',
            'size' => 'Size 5',
            'unit' => 'pc',
            'tracking_type' => 'serial',
            'purchase_price' => 850,
            'selling_price' => 1200,
            'currency' => 'USD',
            'min_stock' => 2,
        ];
    }

    public function instructions(): array
    {
        return [
            __('One row per product. Products are matched by SKU: an existing SKU is updated, a new SKU is created.'),
            __('Empty cells never overwrite existing values. To clear a value, edit the product on screen.'),
            __('New products need: SKU, Name EN and Tracking type ("serial" for implants with serial numbers, "lot" for items counted by quantity).'),
            __('Category: the category code or its name. Brand: created automatically if new. Unit: pc, box, pack…'),
            __('Currency (USD, EUR or IQD) applies to both prices. Use plain numbers, no currency signs.'),
            __('Tracking type cannot change once a product has stock.'),
        ];
    }

    public function begin(DataImport $import, User $user): void
    {
        $this->seenSkus = [];
        $this->seenGtins = [];
        $this->currencies = Currency::query()->pluck('code')->all();

        $this->categories = [];
        foreach (Category::query()->get() as $category) {
            $this->categories[CellParser::key($category->code)] = $category->id;

            foreach ($category->getTranslations('name') as $name) {
                $this->categories[CellParser::key($name)] ??= $category->id;
            }
        }

        $this->brands = Brand::query()->pluck('id', 'name')->mapWithKeys(fn ($id, $name) => [CellParser::key($name) => $id])->all();

        $this->uoms = [];
        foreach (Uom::query()->get() as $uom) {
            $this->uoms[CellParser::key($uom->code)] = $uom->id;

            foreach ($uom->getTranslations('name') as $name) {
                $this->uoms[CellParser::key($name)] ??= $uom->id;
            }
        }
    }

    public function check(array $row, int $rowNumber): RowResult
    {
        $result = new RowResult;
        $sku = CellParser::text($row['sku'] ?? null);

        if ($sku === null) {
            return $result->error(__('SKU is empty.'));
        }

        if (mb_strlen($sku) > 64) {
            $result->error(__('SKU is longer than 64 characters.'));
        }

        if (isset($this->seenSkus[$sku])) {
            return $result->error(__('SKU :sku is also on row :row of this file.', ['sku' => $sku, 'row' => $this->seenSkus[$sku]]));
        }

        $this->seenSkus[$sku] = $rowNumber;

        /** @var Product|null $product */
        $product = Product::query()->with(['category', 'brand', 'uom'])->where('sku', $sku)->first();
        $result->isNew = $product === null;
        $attributes = $result->isNew ? ['sku' => $sku] : [];

        $this->checkGtin($row, $sku, $product, $result, $attributes);
        $this->checkNames($row, $product, $result, $attributes);
        $this->checkLookups($row, $product, $result, $attributes);
        $this->checkTracking($row, $product, $result, $attributes);
        $this->checkPrices($row, $product, $result, $attributes);

        $size = CellParser::text($row['size'] ?? null);
        if ($size !== null) {
            $result->change('size', $product?->size, $size);
            $attributes['size'] = $size;
        }

        [$min, $minValid] = CellParser::integer($row['min_stock'] ?? null);
        if (! $minValid) {
            $result->error(__('Min stock must be a whole number (0 or more).'));
        } elseif ($min !== null) {
            $result->change('min_stock', $product?->min_stock_default, $min);
            $attributes['min_stock_default'] = $min;
        }

        if ($result->isNew) {
            $attributes['uom_id'] ??= $this->uoms['pc'] ?? Uom::query()->value('id');
            $attributes['is_implant'] = ($attributes['tracking_type'] ?? null) === TrackingType::Serial->value;
            $attributes['requires_expiry'] = true;
            $attributes['is_active'] = true;
        } elseif (! $result->errors) {
            // Only fields that really change are written.
            $attributes = array_intersect_key($attributes, $this->attributeKeysFor(array_keys($result->diff)));
        }

        $result->normalized = ['sku' => $sku, 'attributes' => $attributes];
        $result->targetType = Product::class;
        $result->targetId = $product?->id;

        return $result;
    }

    public function apply(DataImportRow $row, DataImport $import, User $user): void
    {
        $attributes = $row->normalized['attributes'] ?? [];
        $sku = (string) ($row->normalized['sku'] ?? '');

        DB::transaction(function () use ($row, $attributes, $sku) {
            $brandName = $attributes['_brand_name'] ?? null;
            unset($attributes['_brand_name']);

            if ($brandName !== null && ! isset($attributes['brand_id'])) {
                $attributes['brand_id'] = Brand::query()->firstOrCreate(['name' => $brandName])->id;
            }

            $product = Product::query()->lockForUpdate()->where('sku', $sku)->first();

            try {
                if ($product === null) {
                    if ($row->action !== ImportRowAction::Create) {
                        throw new RowFailed(__('Product :sku was deleted after the preview.', ['sku' => $sku]));
                    }

                    $product = $this->products->create($attributes);
                } else {
                    if (isset($attributes['name'])) {
                        // Keep languages the file left empty.
                        $attributes['name'] = array_merge($product->getTranslations('name'), $attributes['name']);
                    }

                    $this->products->update($product, $attributes);
                }
            } catch (ValidationException $e) {
                throw new RowFailed(implode(' ', array_merge(...array_values($e->errors()))));
            }

            $row->target()->associate($product);
        });
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $attributes
     */
    private function checkGtin(array $row, string $sku, ?Product $product, RowResult $result, array &$attributes): void
    {
        $gtin = CellParser::text($row['gtin'] ?? null);

        if ($gtin === null) {
            return;
        }

        if (! Gtin::isValid($gtin)) {
            $result->error(__('GTIN :gtin is not valid (8, 12, 13 or 14 digits with a correct check digit).', ['gtin' => $gtin]));

            return;
        }

        $variants = Gtin::variants((string) Gtin::toGtin14($gtin));
        $other = Product::query()->whereIn('gtin', $variants)->where('sku', '<>', $sku)->value('sku')
            ?? ProductBarcode::query()->whereIn('barcode', $variants)->whereHas('product', fn ($q) => $q->where('sku', '<>', $sku))->first()?->product?->sku;

        if ($other !== null) {
            $result->error(__('GTIN :gtin already belongs to :sku.', ['gtin' => $gtin, 'sku' => $other]));

            return;
        }

        if (isset($this->seenGtins[$gtin])) {
            $result->error(__('GTIN :gtin is also used by :sku in this file.', ['gtin' => $gtin, 'sku' => $this->seenGtins[$gtin]]));

            return;
        }

        $this->seenGtins[$gtin] = $sku;
        $result->change('gtin', $product?->gtin, $gtin);
        $attributes['gtin'] = $gtin;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $attributes
     */
    private function checkNames(array $row, ?Product $product, RowResult $result, array &$attributes): void
    {
        $names = [];

        foreach (array_keys(app(LocaleRegistry::class)->all()) as $locale) {
            $value = CellParser::text($row["name_{$locale}"] ?? null);

            if ($value !== null) {
                $names[$locale] = mb_substr($value, 0, 191);
                $result->change("name_{$locale}", $product?->getTranslation('name', $locale, false) ?: null, $names[$locale]);
            }
        }

        if ($product === null && blank($names['en'] ?? null)) {
            $result->error(__('Name EN is required for a new product.'));
        }

        if ($names !== []) {
            $attributes['name'] = $names;
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $attributes
     */
    private function checkLookups(array $row, ?Product $product, RowResult $result, array &$attributes): void
    {
        $category = CellParser::text($row['category'] ?? null);

        if ($category !== null) {
            $id = $this->categories[CellParser::key($category)] ?? null;

            if ($id === null) {
                $result->error(__('Category ":name" was not found. Add it in Catalog → Categories, or use its code.', ['name' => $category]));
            } else {
                $result->change('category', $product?->category?->code, Category::query()->find($id)?->code);
                $attributes['category_id'] = $id;
            }
        }

        $brand = CellParser::text($row['brand'] ?? null);

        if ($brand !== null) {
            $id = $this->brands[CellParser::key($brand)] ?? null;
            $result->change('brand', $product?->brand?->name, $brand);

            if ($id === null) {
                $result->warn(__('Brand ":name" is new and will be created.', ['name' => $brand]));
                $attributes['_brand_name'] = mb_substr($brand, 0, 120);
            } else {
                $attributes['brand_id'] = $id;
            }
        }

        $unit = CellParser::text($row['unit'] ?? null);

        if ($unit !== null) {
            $id = $this->uoms[CellParser::key($unit)] ?? null;

            if ($id === null) {
                $result->error(__('Unit ":name" was not found (use pc, box or pack).', ['name' => $unit]));
            } else {
                $result->change('unit', $product?->uom?->code, Uom::query()->find($id)?->code);
                $attributes['uom_id'] = $id;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $attributes
     */
    private function checkTracking(array $row, ?Product $product, RowResult $result, array &$attributes): void
    {
        $raw = CellParser::text($row['tracking_type'] ?? null);

        if ($raw === null) {
            if ($product === null) {
                $result->error(__('Tracking type is required for a new product ("serial" or "lot").'));
            }

            return;
        }

        $tracking = $this->trackingType($raw);

        if ($tracking === null) {
            $result->error(__('Tracking type ":value" is not valid — use "serial" or "lot".', ['value' => $raw]));

            return;
        }

        if ($product !== null && $tracking !== $product->tracking_type && $this->products->hasStock($product)) {
            $result->error(__('Tracking type cannot change: this product already has stock.'));

            return;
        }

        $result->change('tracking_type', $product?->tracking_type->value, $tracking->value);
        $attributes['tracking_type'] = $tracking->value;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $attributes
     */
    private function checkPrices(array $row, ?Product $product, RowResult $result, array &$attributes): void
    {
        $currency = CellParser::text($row['currency'] ?? null);
        $currency = $currency !== null ? mb_strtoupper($currency) : null;

        if ($currency !== null && ! in_array($currency, $this->currencies, true)) {
            $result->error(__('Currency ":value" is not valid — use USD, EUR or IQD.', ['value' => $currency]));
            $currency = null;
        }

        foreach (['purchase', 'selling'] as $side) {
            [$price, $valid] = CellParser::decimal($row["{$side}_price"] ?? null);

            if (! $valid) {
                $result->error($side === 'purchase' ? __('Purchase price must be a number (0 or more).') : __('Selling price must be a number (0 or more).'));

                continue;
            }

            if ($price === null) {
                continue;
            }

            $priceCurrency = $currency ?? $product?->{"{$side}_currency"};

            if ($priceCurrency === null) {
                $result->error(__('Add the currency for the prices.'));

                continue;
            }

            $result->change("{$side}_price", $product?->{"{$side}_price"}, $price);
            $attributes["{$side}_price"] = $price;
            $attributes["{$side}_currency"] = $priceCurrency;

            if ($currency !== null) {
                $result->change("{$side}_currency", $product?->{"{$side}_currency"}, $currency);
            }
        }
    }

    private function trackingType(string $value): ?TrackingType
    {
        $key = CellParser::key($value);

        if (in_array($key, ['serial', 's', 'sn', 'serial number'], true)) {
            return TrackingType::Serial;
        }

        if (in_array($key, ['lot', 'l', 'batch', 'quantity'], true)) {
            return TrackingType::Lot;
        }

        foreach (TrackingType::cases() as $case) {
            foreach (array_keys(app(LocaleRegistry::class)->all()) as $locale) {
                if ($key === CellParser::key(__($case === TrackingType::Serial ? 'Serial number' : 'Lot + quantity', [], $locale))) {
                    return $case;
                }
            }
        }

        return null;
    }

    /**
     * Diff field → product attribute(s) to write.
     *
     * @param  list<string>  $diffFields
     * @return array<string, true>
     */
    private function attributeKeysFor(array $diffFields): array
    {
        $map = [
            'gtin' => ['gtin'],
            'name_en' => ['name'], 'name_ar' => ['name'], 'name_ckb' => ['name'],
            'category' => ['category_id'],
            'brand' => ['brand_id', '_brand_name'],
            'unit' => ['uom_id'],
            'size' => ['size'],
            'tracking_type' => ['tracking_type'],
            'purchase_price' => ['purchase_price', 'purchase_currency'],
            'purchase_currency' => ['purchase_price', 'purchase_currency'],
            'selling_price' => ['selling_price', 'selling_currency'],
            'selling_currency' => ['selling_price', 'selling_currency'],
            'min_stock' => ['min_stock_default'],
        ];

        $keys = [];

        foreach ($diffFields as $field) {
            foreach ($map[$field] ?? [] as $attribute) {
                $keys[$attribute] = true;
            }
        }

        return $keys;
    }
}
