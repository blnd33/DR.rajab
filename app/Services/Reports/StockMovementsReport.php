<?php

namespace App\Services\Reports;

use App\Enums\MovementType;
use App\Models\Branch;
use App\Models\Product;
use App\Models\StockMovement;
use Brick\Math\BigDecimal;

/**
 * What moved in a period (Module 24): receipts, transfers, surgery use, sales, write-offs…
 * by movement type, branch or product, with the cost of what moved.
 */
class StockMovementsReport extends Report
{
    public function __construct(private readonly SnapshotConverters $fx) {}

    public static function key(): string
    {
        return 'stock-movements';
    }

    public function title(): string
    {
        return __('Stock movements');
    }

    public function description(): ?string
    {
        return __('Every change on the stock ledger in the period, counted per movement type.');
    }

    public function filters(): array
    {
        return ['period', 'branch', 'currency', 'group'];
    }

    public function groups(): array
    {
        return [
            'type' => __('Movement type'),
            'type_branch' => __('Movement type and branch'),
            'product' => __('Product'),
        ];
    }

    public function columns(ReportContext $context): array
    {
        $head = match ($context->group) {
            'product' => [ReportColumn::code('sku', __('SKU')), ReportColumn::text('product', __('Product'))],
            'type_branch' => [ReportColumn::text('type', __('Movement')), ReportColumn::text('branch', __('Branch'))],
            default => [ReportColumn::text('type', __('Movement'))],
        };

        return [
            ...$head,
            ReportColumn::number('movements', __('Movements')),
            ReportColumn::number('quantity', __('Quantity')),
            ReportColumn::money('cost', __('Cost moved'), profit: true),
        ];
    }

    public function rows(ReportContext $context): array
    {
        $branchSql = 'COALESCE(to_branch_id, from_branch_id)';

        $movements = StockMovement::query()
            ->whereBetween('occurred_at', [$context->from, $context->until])
            ->when($context->branchIds !== null, fn ($q) => $q->where(fn ($w) => $w->whereIn('from_branch_id', $context->branchIds)->orWhereIn('to_branch_id', $context->branchIds)))
            ->groupByRaw("type, product_id, {$branchSql}, cost_currency, fx_snapshot_id")
            ->selectRaw("type, product_id, {$branchSql} AS branch_id, cost_currency, fx_snapshot_id, COUNT(*) AS movements, SUM(quantity) AS quantity, SUM(quantity * COALESCE(unit_cost, 0)) AS cost")
            ->toBase()->get();

        $branches = self::names(Branch::class, $movements->pluck('branch_id'));
        $products = Product::query()->whereIn('id', $movements->pluck('product_id')->unique())->get()->keyBy('id');
        $rows = [];

        foreach ($movements as $row) {
            $type = MovementType::tryFrom((string) $row->type);
            $key = match ($context->group) {
                'product' => 'p'.$row->product_id,
                'type_branch' => $row->type.'-b'.$row->branch_id,
                default => (string) $row->type,
            };
            $product = $products->get((int) $row->product_id);
            $cost = $this->fx->convert((string) $row->cost, $row->cost_currency, $context->currency, $row->fx_snapshot_id !== null ? (int) $row->fx_snapshot_id : null, $context) ?? BigDecimal::zero();

            $rows[$key] ??= [
                'key' => $key,
                'type' => $type?->getLabel() ?? (string) $row->type,
                'branch' => $branches[(int) $row->branch_id] ?? '—',
                'sku' => $product->sku ?? '—',
                'product' => $product->name ?? '—',
                'movements' => 0,
                'quantity' => 0,
                'cost' => '0',
            ];
            $rows[$key]['movements'] += (int) $row->movements;
            $rows[$key]['quantity'] += (int) $row->quantity;
            $rows[$key]['cost'] = self::money(BigDecimal::of($rows[$key]['cost'])->plus($cost));
        }

        return array_values($rows);
    }
}
