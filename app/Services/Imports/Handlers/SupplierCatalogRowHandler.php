<?php

namespace App\Services\Imports\Handlers;

use App\Enums\TrackingType;
use App\Enums\UsageLevel;
use App\Models\Brand;
use App\Models\DataImport;
use App\Models\DataImportRow;
use App\Models\InstrumentType;
use App\Models\KitType;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Uom;
use App\Models\User;
use App\Services\Catalog\ProductService;
use App\Services\Catalog\SupplierCatalogService;
use App\Services\Imports\Contracts\ReadsWorkbook;
use App\Services\Imports\Contracts\RowHandler;
use App\Services\Imports\ImportFileInvalid;
use App\Services\Imports\ImportPipeline;
use App\Services\Imports\Lima\LimaWorkbookReader;
use App\Services\Imports\RowFailed;
use App\Services\Imports\RowResult;
use App\Support\Gs1\Gtin;
use Illuminate\Validation\ValidationException;

/**
 * Supplier catalog (the doctor's Lima / Enovis workbook, as he sends it): implants become
 * products, instrument sets become kit types with their contents, the other instrument codes
 * become instrument types. Matched by code: re-importing a newer workbook updates them.
 */
class SupplierCatalogRowHandler implements ReadsWorkbook, RowHandler
{
    private ?int $pieceUom = null;

    public function __construct(
        private readonly LimaWorkbookReader $reader,
        private readonly ProductService $products,
        private readonly SupplierCatalogService $catalog,
    ) {}

    public function columns(): array
    {
        return [
            'kind' => 'Type',
            'code' => 'Code',
            'name' => 'Description',
            'area' => 'Area',
            'section' => 'Group',
            'usage' => 'Usage',
            'size' => 'Size',
            'sheet' => 'Sheet',
            'row' => 'Sheet row',
        ];
    }

    public function requiredColumns(): array
    {
        return [];
    }

    public function example(): array
    {
        return [];
    }

    public function instructions(): array
    {
        return [
            __('Upload the supplier workbook as it is: per area (Hip, Knee, Shoulder) an Implants sheet, an Instrument Sets Summary and the Instrument Sets Details.'),
            __('Colours are read from each sheet\'s own key (colour in column D, meaning in column E).'),
        ];
    }

    public function readWorkbook(string $path): array
    {
        $catalog = $this->reader->read($path);
        $rows = [];
        $number = 0;

        // Instruments before sets: a set's contents point at instrument types.
        foreach (['implants', 'instruments', 'sets'] as $group) {
            foreach ($catalog[$group] as $record) {
                $rows[++$number] = $record;
            }
        }

        if ($rows === []) {
            throw new ImportFileInvalid(__('No items were found in the workbook.'));
        }

        if (count($rows) > ImportPipeline::MAX_ROWS) {
            throw new ImportFileInvalid(__('The file has more than :max rows. Split it into smaller files.', ['max' => ImportPipeline::MAX_ROWS]));
        }

        return $rows;
    }

    public function begin(DataImport $import, User $user): void
    {
        $this->pieceUom = Uom::query()->where('code', 'pc')->value('id') ?? Uom::query()->value('id');
    }

    public function check(array $row, int $rowNumber): RowResult
    {
        return match ($row['kind'] ?? null) {
            'implant' => $this->checkImplant($row),
            'instrument' => $this->checkInstrument($row),
            'set' => $this->checkSet($row),
            default => (new RowResult)->error(__('Unknown row.')),
        };
    }

    public function apply(DataImportRow $row, DataImport $import, User $user): void
    {
        $data = $row->normalized ?? [];

        $target = match ($row->raw['kind'] ?? null) {
            'implant' => $this->applyImplant($data),
            'instrument' => $this->catalog->instrumentType((string) $data['code'], (string) $data['name']),
            'set' => $this->catalog->syncKitType(
                (string) $data['code'],
                (string) $data['name'],
                $data['description'] ?? null,
                UsageLevel::tryFrom((string) ($data['usage'] ?? '')),
                $data['items'] ?? [],
            ),
            default => throw new RowFailed(__('Unknown row.')),
        };

        $row->target()->associate($target);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function checkImplant(array $row): RowResult
    {
        $result = new RowResult;
        $code = (string) $row['code'];

        if (filled($row['conflict'] ?? null)) {
            return $result->error((string) $row['conflict']);
        }

        /** @var Product|null $product */
        $product = Product::query()->with(['category.parent', 'brand'])->where('sku', $code)->first();
        $result->isNew = $product === null;
        $usage = UsageLevel::tryFrom((string) ($row['usage'] ?? ''));
        $name = mb_substr((string) $row['name'], 0, 191);
        $category = $this->categoryLabel((string) $row['area'], $row['section'] ?? null);

        $result->change('name_en', $product?->getTranslation('name', 'en', false) ?: null, $name);
        $result->change('category', $product?->category ? $this->categoryLabel($product->category->parent->code ?? $product->category->code, $product->category->parent ? $product->category->getTranslation('name', 'en', false) : null) : null, $category);
        $result->change('brand', $product?->brand?->name, $row['brand']);
        $result->change('usage_level', $product?->usage_level?->getLabel(), $usage?->getLabel());

        if ($row['size'] !== null) {
            $result->change('size', $product?->size, $row['size']);
        }

        $attributes = [
            'name' => ['en' => $name],
            'size' => $row['size'] ?? $product?->size,
            'usage_level' => $usage?->value,
            '_brand_name' => $row['brand'],
            '_category' => ['area' => $row['area'], 'section' => $row['section'] ?? null],
        ];

        if ($product === null) {
            // Every item stays usable, "Not used" ones included (they sort last). An item an
            // admin archived by hand stays archived on a re-import.
            $attributes['is_active'] = true;
        }

        $gtin = $row['gtin'] ?? null;

        if ($gtin !== null && blank($product?->gtin)) {
            $owner = Product::query()->whereIn('gtin', Gtin::variants((string) Gtin::toGtin14($gtin)))->where('sku', '<>', $code)->value('sku')
                ?? ProductBarcode::query()->whereIn('barcode', Gtin::variants((string) Gtin::toGtin14($gtin)))->whereHas('product', fn ($q) => $q->where('sku', '<>', $code))->first()?->product?->sku;

            if ($owner !== null) {
                $result->warn(__('GTIN :gtin already belongs to :sku.', ['gtin' => $gtin, 'sku' => $owner]));
            } elseif (Gtin::isValid($gtin)) {
                $result->change('gtin', null, $gtin);
                $attributes['gtin'] = $gtin;
            }
        }

        if ($product === null) {
            $attributes += [
                'sku' => $code,
                'tracking_type' => TrackingType::Lot->value,
                'is_implant' => true,
                'requires_expiry' => true,
                'uom_id' => $this->pieceUom,
            ];
        }

        $result->normalized = ['sku' => $code, 'attributes' => $attributes];
        $result->targetType = Product::class;
        $result->targetId = $product?->id;

        return $result;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function checkInstrument(array $row): RowResult
    {
        $result = new RowResult;
        $code = mb_strtoupper((string) $row['code']);

        if (mb_strlen($code) > 32) {
            return $result->error(__('The code is longer than 32 characters.'));
        }

        $type = InstrumentType::query()->where('code', $code)->first();
        $result->isNew = $type === null;
        $result->change('name_en', $type?->getTranslation('name', 'en', false) ?: null, $row['name']);

        $result->normalized = ['code' => $code, 'name' => mb_substr((string) $row['name'], 0, 191)];
        $result->targetType = InstrumentType::class;
        $result->targetId = $type?->id;

        return $result;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function checkSet(array $row): RowResult
    {
        $result = new RowResult;
        $code = mb_strtoupper((string) $row['code']);

        if (mb_strlen($code) > 32) {
            return $result->error(__('The code is longer than 32 characters.'));
        }

        /** @var KitType|null $kitType */
        $kitType = KitType::query()->with('templateItems.instrumentType')->where('code', $code)->first();
        $result->isNew = $kitType === null;
        $usage = UsageLevel::tryFrom((string) ($row['usage'] ?? ''));
        $area = __(SupplierCatalogService::AREAS[$row['area']] ?? (string) $row['area'], [], 'en');
        $description = filled($row['family'] ?? null) ? "{$area} · {$row['family']}" : $area;
        $items = $row['items'] ?? [];

        $result->change('name_en', $kitType?->getTranslation('name', 'en', false) ?: null, $row['name']);
        $result->change('description', $kitType?->getTranslation('description', 'en', false) ?: null, $description);
        $result->change('usage_level', $kitType?->usage_level?->getLabel(), $usage?->getLabel());

        if ($items !== []) {
            $old = $kitType?->templateItems->whereNull('product_id')
                ->map(fn ($item) => $item->instrumentType?->code.'×'.$item->expected_qty.($item->is_required ? '' : '?'))->sort()->values()->all() ?? [];
            $new = collect($items)->map(fn ($item) => mb_strtoupper($item['code']).'×'.$item['qty'].($item['required'] ? '' : '?'))->sort()->values()->all();

            if ($old !== $new) {
                $result->change('contents', $old === [] ? null : trans_choice(':count instrument|:count instruments', count($old), ['count' => count($old)]), trans_choice(':count instrument|:count instruments', count($new), ['count' => count($new)]));
            }
        } else {
            $result->warn(__('No contents for this set on the details sheets.'));
        }

        $result->normalized = ['code' => $code, 'name' => mb_substr((string) $row['name'], 0, 191), 'description' => $description, 'usage' => $usage?->value, 'items' => $items];
        $result->targetType = KitType::class;
        $result->targetId = $kitType?->id;

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyImplant(array $data): Product
    {
        $attributes = $data['attributes'] ?? [];
        $sku = (string) ($data['sku'] ?? '');
        $category = $attributes['_category'] ?? null;
        $brand = $attributes['_brand_name'] ?? null;
        unset($attributes['_category'], $attributes['_brand_name']);

        if (is_array($category)) {
            $attributes['category_id'] = $this->catalog->category((string) $category['area'], $category['section'] ?? null)->id;
        }

        if (filled($brand)) {
            $attributes['brand_id'] = Brand::query()->firstOrCreate(['name' => mb_substr((string) $brand, 0, 120)])->id;
        }

        $product = Product::query()->lockForUpdate()->where('sku', $sku)->first();

        try {
            if ($product === null) {
                return $this->products->create($attributes);
            }

            // Keep the other languages of the name.
            $attributes['name'] = array_merge($product->getTranslations('name'), $attributes['name'] ?? []);

            return $this->products->update($product, $attributes);
        } catch (ValidationException $e) {
            throw new RowFailed(implode(' ', array_merge(...array_values($e->errors()))));
        }
    }

    private function categoryLabel(string $area, ?string $section): string
    {
        $areaName = __(SupplierCatalogService::AREAS[$area] ?? $area, [], 'en');

        return blank($section) ? $areaName : "{$areaName} → {$section}";
    }
}
