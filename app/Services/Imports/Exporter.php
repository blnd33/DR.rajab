<?php

namespace App\Services\Imports;

use App\Enums\ImportType;
use App\Enums\StockStatus;
use App\Exports\ArraySheet;
use App\Models\BranchProductSetting;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockUnit;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;

/**
 * Excel exports in exactly the import column format (Module 23), so a file can be
 * exported, edited and imported back. Always Western digits, dates YYYY-MM-DD.
 */
class Exporter
{
    public function __construct(
        private readonly ImportPipeline $pipeline,
        private readonly BranchScopeResolver $scope,
    ) {}

    public function sheet(ImportType $type, User $user): ArraySheet
    {
        $columns = $this->pipeline->handler($type)->columns();

        $rows = match ($type) {
            ImportType::Products => $this->products(),
            ImportType::Stock => $this->stock($user),
            ImportType::MinStock => $this->minStock($user),
        };

        $ordered = array_map(fn (array $row) => array_map(fn (string $key) => $row[$key] ?? '', array_keys($columns)), $rows);

        return new ArraySheet($type->getLabel(), array_values($columns), $ordered, range(1, count($columns)));
    }

    public function filename(ImportType $type): string
    {
        return 'orthotrack-'.str_replace('_', '-', $type->value).'-'.now(config('orthotrack.timezone'))->format('Y-m-d').'.xlsx';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function products(): array
    {
        $rows = [];

        Product::query()->with(['category', 'brand', 'uom'])->orderBy('sku')->each(function (Product $product) use (&$rows) {
            $rows[] = [
                'sku' => $product->sku,
                'gtin' => $product->gtin,
                'name_en' => $product->getTranslation('name', 'en', false),
                'name_ar' => $product->getTranslation('name', 'ar', false),
                'name_ckb' => $product->getTranslation('name', 'ckb', false),
                'category' => $product->category?->code,
                'brand' => $product->brand?->name,
                'size' => $product->size,
                'unit' => $product->uom?->code,
                'tracking_type' => $product->tracking_type->value,
                'purchase_price' => $product->purchase_price !== null ? (float) $product->purchase_price : '',
                'selling_price' => $product->selling_price !== null ? (float) $product->selling_price : '',
                'currency' => $product->purchase_currency ?? $product->selling_currency,
                'min_stock' => $product->min_stock_default,
            ];
        }, 500);

        return $rows;
    }

    /**
     * Stock on hand in the branches the user may export.
     *
     * @return list<array<string, mixed>>
     */
    private function stock(User $user): array
    {
        $rows = [];
        $canSeeCost = $this->scope->hasAny($user, 'stock.view_cost');

        $this->scope->apply(StockUnit::query(), $user, 'stock.export')
            ->with(['product', 'branch'])
            ->whereIn('status', StockStatus::onHand())
            ->orderBy('branch_id')->orderBy('product_id')->orderBy('serial')
            ->each(function (StockUnit $unit) use (&$rows, $canSeeCost) {
                $rows[] = [
                    'sku' => $unit->product->sku,
                    'serial' => $unit->serial,
                    'lot' => $unit->lot,
                    'expiry_date' => $unit->expiry_date?->format('Y-m-d'),
                    'quantity' => 1,
                    'branch' => $unit->branch->code,
                    'purchase_price' => $canSeeCost ? (float) $unit->base_unit_cost : '',
                    'currency' => $canSeeCost ? $unit->cost_currency : '',
                ];
            }, 500);

        $this->scope->apply(StockBatch::query(), $user, 'stock.export')
            ->with(['product', 'branch'])
            ->where('quantity', '>', 0)
            ->orderBy('branch_id')->orderBy('product_id')->orderBy('expiry_date')
            ->each(function (StockBatch $batch) use (&$rows, $canSeeCost) {
                $rows[] = [
                    'sku' => $batch->product->sku,
                    'serial' => '',
                    'lot' => $batch->lot,
                    'expiry_date' => $batch->expiry_date?->format('Y-m-d'),
                    'quantity' => $batch->quantity,
                    'branch' => $batch->branch->code,
                    'purchase_price' => $canSeeCost ? (float) $batch->base_unit_cost : '',
                    'currency' => $canSeeCost ? $batch->cost_currency : '',
                ];
            }, 500);

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function minStock(User $user): array
    {
        $rows = [];

        $this->scope->apply(BranchProductSetting::query(), $user, 'stock.export')
            ->with(['product', 'branch'])
            ->orderBy('branch_id')
            ->each(function (BranchProductSetting $setting) use (&$rows) {
                $rows[] = [
                    'sku' => $setting->product->sku,
                    'branch' => $setting->branch->code,
                    'min_stock' => $setting->min_stock,
                    'max_stock' => $setting->max_stock,
                    'reorder_qty' => $setting->reorder_qty,
                ];
            }, 500);

        return $rows;
    }
}
