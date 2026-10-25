<?php

namespace App\Services\Imports\Handlers;

use App\Models\Branch;
use App\Models\BranchProductSetting;
use App\Models\DataImport;
use App\Models\DataImportRow;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\ProductService;
use App\Services\Imports\CellParser;
use App\Services\Imports\Contracts\RowHandler;
use App\Services\Imports\RowFailed;
use App\Services\Imports\RowResult;
use App\Services\Permissions\BranchScopeResolver;
use Illuminate\Validation\ValidationException;

/**
 * Template C — minimum stock per branch (Module 17), matched by SKU + branch.
 */
class MinStockRowHandler implements RowHandler
{
    /** @var array<string, Branch> */
    private array $branches = [];

    /** @var list<int>|null */
    private ?array $allowedBranchIds = [];

    /** @var array<string, int> */
    private array $seen = [];

    public function __construct(
        private readonly ProductService $products,
        private readonly BranchScopeResolver $scope,
    ) {}

    public function columns(): array
    {
        return [
            'sku' => 'SKU',
            'branch' => 'Branch',
            'min_stock' => 'Min stock',
            'max_stock' => 'Max stock',
            'reorder_qty' => 'Reorder quantity',
        ];
    }

    public function requiredColumns(): array
    {
        return ['sku', 'branch', 'min_stock'];
    }

    public function example(): array
    {
        return ['sku' => 'KN-TIB-5', 'branch' => 'DHK', 'min_stock' => 3, 'max_stock' => 10, 'reorder_qty' => 5];
    }

    public function instructions(): array
    {
        return [
            __('One row per product and branch. The branch minimum replaces the product\'s default minimum in that branch.'),
            __('Min stock is required. Max stock and Reorder quantity are optional; empty cells keep the current value.'),
            __('A low-stock alert is shown when the quantity on the shelf is below the minimum.'),
        ];
    }

    public function begin(DataImport $import, User $user): void
    {
        $this->seen = [];
        $this->allowedBranchIds = $this->scope->branchIdsFor($user, 'stock.min_levels');
        $this->branches = Branch::query()->get()->keyBy(fn (Branch $b) => CellParser::key($b->code))->all();
    }

    public function check(array $row, int $rowNumber): RowResult
    {
        $result = new RowResult;
        $sku = CellParser::text($row['sku'] ?? null);
        $code = CellParser::text($row['branch'] ?? null);

        $product = $sku !== null ? Product::query()->where('sku', $sku)->first() : null;
        $branch = $code !== null ? ($this->branches[CellParser::key($code)] ?? null) : null;

        if ($product === null) {
            $result->error($sku === null ? __('SKU is empty.') : __('No product with SKU :sku.', ['sku' => $sku]));
        }

        if ($branch === null) {
            $result->error($code === null ? __('Branch is empty.') : __('Branch :code does not exist.', ['code' => $code]));
        } elseif ($this->allowedBranchIds !== null && ! in_array($branch->id, $this->allowedBranchIds, true)) {
            $result->error(__('You may not change minimum stock in branch :code.', ['code' => $branch->code]));
        }

        [$min, $minValid] = CellParser::integer($row['min_stock'] ?? null);
        [$max, $maxValid] = CellParser::integer($row['max_stock'] ?? null);
        [$reorder, $reorderValid] = CellParser::integer($row['reorder_qty'] ?? null);

        if (! $minValid || $min === null) {
            $result->error(__('Min stock must be a whole number (0 or more).'));
        }

        if (! $maxValid || ! $reorderValid) {
            $result->error(__('Max stock and Reorder quantity must be whole numbers.'));
        }

        if ($result->errors !== [] || $product === null || $branch === null) {
            return $result;
        }

        $key = $product->id.'|'.$branch->id;

        if (isset($this->seen[$key])) {
            return $result->error(__('This product and branch is also on row :row of this file.', ['row' => $this->seen[$key]]));
        }

        $this->seen[$key] = $rowNumber;

        $setting = BranchProductSetting::query()->where('product_id', $product->id)->where('branch_id', $branch->id)->first();
        $max ??= $setting?->max_stock;
        $reorder ??= $setting?->reorder_qty;

        if ($max !== null && $max < $min) {
            return $result->error(__('Max stock cannot be below the minimum.'));
        }

        $result->isNew = $setting === null;
        $result->change('min_stock', $setting->min_stock ?? $product->min_stock_default, $min);
        $result->change('max_stock', $setting?->max_stock, $max);
        $result->change('reorder_qty', $setting?->reorder_qty, $reorder);

        $result->normalized = ['product_id' => $product->id, 'branch_id' => $branch->id, 'min_stock' => $min, 'max_stock' => $max, 'reorder_qty' => $reorder];
        $result->targetType = BranchProductSetting::class;
        $result->targetId = $setting?->id;

        return $result;
    }

    public function apply(DataImportRow $row, DataImport $import, User $user): void
    {
        $data = $row->normalized ?? [];

        if (! $this->scope->allows($user, 'stock.min_levels', (int) $data['branch_id'])) {
            throw new RowFailed(__('You may not change minimum stock in this branch.'));
        }

        try {
            $setting = $this->products->setBranchMinimum(
                Product::query()->findOrFail($data['product_id']),
                (int) $data['branch_id'],
                (int) $data['min_stock'],
                $data['max_stock'] ?? null,
                $data['reorder_qty'] ?? null,
            );
        } catch (ValidationException $e) {
            throw new RowFailed(implode(' ', array_merge(...array_values($e->errors()))));
        }

        if ($setting !== null) {
            $row->target()->associate($setting);
        }
    }
}
