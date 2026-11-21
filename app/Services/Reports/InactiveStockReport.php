<?php

namespace App\Services\Reports;

use App\Enums\MovementType;
use App\Enums\StockStatus;
use App\Models\Branch;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\StockUnit;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;

/**
 * Inactive Stock Volume (and, with values, Inactive Stock Value): what is on hand in a station
 * and has not been used there for the chosen number of days — nothing sold, used in surgery,
 * written off, returned to a supplier or counted out — although it has been in stock that long.
 */
class InactiveStockReport extends Report
{
    /** Whether the screen shows the value at true cost. */
    protected bool $withValue = false;

    public function __construct(private readonly OnHandStock $stock) {}

    public static function key(): string
    {
        return 'inactive-stock-volume';
    }

    public function title(): string
    {
        return __('Inactive stock volume');
    }

    public function description(): ?string
    {
        return __('Pieces on hand that have not been used (sold, used in surgery, written off, returned or counted out) for the chosen number of days, although they have been in stock that long.');
    }

    public function filters(): array
    {
        return $this->withValue ? ['idle', 'branch', 'currency', 'group'] : ['idle', 'branch', 'group'];
    }

    public function groups(): array
    {
        return [
            'item_branch' => __('Item and station'),
            'item' => __('Item'),
            'branch' => __('Station'),
            'specialty' => __('Specialty'),
        ];
    }

    public function columns(ReportContext $context): array
    {
        $amounts = [
            ReportColumn::number('quantity', __('On hand')),
            ...($this->withValue ? [ReportColumn::money('value', __('Value (true cost)'))] : []),
        ];

        return match ($context->group) {
            'item' => [
                ReportColumn::code('sku', __('SKU')), ReportColumn::text('item', __('Item')),
                ReportColumn::number('stations', __('Stations'), total: false),
                ...$amounts,
                ReportColumn::text('last_used', __('Last used')),
            ],
            'branch' => [ReportColumn::text('branch', __('Station')), ReportColumn::number('items', __('Items'), total: false), ...$amounts],
            'specialty' => [ReportColumn::text('specialty', __('Specialty')), ReportColumn::number('items', __('Items'), total: false), ...$amounts],
            default => [
                ReportColumn::code('sku', __('SKU')), ReportColumn::text('item', __('Item')), ReportColumn::text('branch', __('Station')),
                ...$amounts,
                ReportColumn::text('last_used', __('Last used')),
                ReportColumn::text('since', __('In stock since')),
                ReportColumn::number('idle_days', __('Days not used'), total: false),
            ],
        };
    }

    public function rows(ReportContext $context): array
    {
        $lines = $this->inactive($context);
        $items = $this->stock->items(array_column($lines, 'product_id'));
        $branches = self::names(Branch::class, array_column($lines, 'branch_id'));
        $rows = [];

        foreach ($lines as $line) {
            $item = $items[$line['product_id']] ?? ['sku' => '—', 'name' => '—', 'specialty' => __('No specialty')];
            $key = match ($context->group) {
                'item' => 'p'.$line['product_id'],
                'branch' => 'b'.$line['branch_id'],
                'specialty' => 's'.$item['specialty'],
                default => 'p'.$line['product_id'].'-b'.$line['branch_id'],
            };

            $row = $rows[$key] ?? [
                'key' => $key, 'sku' => $item['sku'], 'item' => $item['name'], 'specialty' => $item['specialty'],
                'branch' => $branches[$line['branch_id']] ?? '—',
                'quantity' => 0, 'value' => '0', 'stations' => [], 'products' => [],
                'used' => null, 'since' => null,
            ];

            $row['quantity'] += $line['quantity'];
            $row['value'] = self::money(BigDecimal::of($row['value'])->plus($line['value']));
            $row['stations'][$line['branch_id']] = true;
            $row['products'][$line['product_id']] = true;
            $row['used'] = $line['used'] !== null && ($row['used'] === null || $line['used']->greaterThan($row['used'])) ? $line['used'] : $row['used'];
            $row['since'] = $row['since'] === null || $line['since']->lessThan($row['since']) ? $line['since'] : $row['since'];
            $rows[$key] = $row;
        }

        $today = now(config('orthotrack.timezone'))->startOfDay();

        return array_values(array_map(fn (array $row) => [
            ...$row,
            'stations' => count($row['stations']),
            'items' => count($row['products']),
            'last_used' => $row['used']?->toDateString() ?? __('Never'),
            'since' => $row['since']?->toDateString(),
            'idle_days' => (int) ($row['used'] ?? $row['since'])->copy()->startOfDay()->diffInDays($today),
            'used' => null,
            'products' => null,
        ], $rows));
    }

    /**
     * The station × item lines that are inactive, with when they were last used and since when
     * the stock has been there.
     *
     * @return list<array{branch_id: int, product_id: int, quantity: int, value: BigDecimal, used: ?Carbon, since: Carbon}>
     */
    private function inactive(ReportContext $context): array
    {
        $tz = config('orthotrack.timezone');
        $cutoff = now($tz)->subDays($context->idleDays);
        $branchIds = $context->branchIds;

        // Since when the stock now on hand has been there (its oldest piece).
        $since = [];
        $units = StockUnit::query()->whereIn('status', StockStatus::onHand())
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->groupBy('branch_id', 'product_id')->selectRaw('branch_id, product_id, MIN(received_at) AS since')->toBase()->get();
        $batches = StockBatch::query()->where('quantity', '>', 0)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->groupBy('branch_id', 'product_id')->selectRaw('branch_id, product_id, MIN(created_at) AS since')->toBase()->get();

        foreach ($units->concat($batches) as $row) {
            $key = $row->branch_id.'-'.$row->product_id;
            $at = Carbon::parse($row->since)->setTimezone($tz);
            $since[$key] = isset($since[$key]) && $since[$key]->lessThan($at) ? $since[$key] : $at;
        }

        // When each item last went out of each station.
        $outputs = array_map(fn (MovementType $t) => $t->value, MovementType::outputs());
        $used = StockMovement::query()
            ->whereNotNull('from_branch_id')
            ->when($branchIds !== null, fn ($q) => $q->whereIn('from_branch_id', $branchIds))
            ->where(fn ($w) => $w->whereIn('type', $outputs)
                ->orWhere(fn ($c) => $c->where('type', MovementType::CountAdjustment->value)->whereNull('to_branch_id')))
            ->groupBy('from_branch_id', 'product_id')
            ->selectRaw('from_branch_id AS branch_id, product_id, MAX(occurred_at) AS used')
            ->toBase()->get()
            ->mapWithKeys(fn ($r) => [$r->branch_id.'-'.$r->product_id => Carbon::parse($r->used)->setTimezone($tz)]);

        $lines = [];

        foreach ($this->stock->lines($context) as $line) {
            $key = $line['branch_id'].'-'.$line['product_id'];
            $there = $since[$key] ?? null;
            $last = $used[$key] ?? null;

            if ($there === null || $there->greaterThan($cutoff) || ($last !== null && $last->greaterThanOrEqualTo($cutoff))) {
                continue;
            }

            $lines[] = $line + ['used' => $last, 'since' => $there];
        }

        return $lines;
    }
}
