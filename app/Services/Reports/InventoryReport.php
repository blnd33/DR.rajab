<?php

namespace App\Services\Reports;

use App\Enums\Governorate;
use App\Models\Branch;
use App\Support\CompanyContext;

/**
 * Stock → Inventory / stock volume: the whole stock — Iraq, Kurdistan, and Iraq including
 * Kurdistan — then per item (with the three totals as columns), governorate, station, or item
 * and station. Pieces on hand now: serial units + lot quantities.
 */
class InventoryReport extends Report
{
    public function __construct(private readonly OnHandStock $stock) {}

    public static function key(): string
    {
        return 'inventory';
    }

    public function title(): string
    {
        return __('Inventory / stock volume');
    }

    public function description(): ?string
    {
        return __('The whole stock on hand now — Iraq, Kurdistan, and Iraq including Kurdistan — per item, governorate or station.');
    }

    public function filters(): array
    {
        return ['branch', 'group'];
    }

    public function groups(): array
    {
        return [
            'region' => __('Iraq, Kurdistan and the whole'),
            'item' => __('Item: Iraq, Kurdistan and the whole'),
            'governorate' => __('Governorate'),
            'branch' => __('Station'),
            'product_branch' => __('Item and station'),
        ];
    }

    public function columns(ReportContext $context): array
    {
        return match ($context->group) {
            'item' => [
                ReportColumn::code('sku', __('SKU')),
                ReportColumn::text('item', __('Item')),
                ReportColumn::number('iraq', __('Iraq')),
                ReportColumn::number('kurdistan', __('Kurdistan')),
                ReportColumn::number('whole', __('Iraq and Kurdistan')),
            ],
            'governorate' => [ReportColumn::text('governorate', __('Governorate')), ReportColumn::text('region', __('Region')), ReportColumn::number('quantity', __('On hand'))],
            'branch' => [ReportColumn::text('branch', __('Station')), ReportColumn::text('region', __('Region')), ReportColumn::number('quantity', __('On hand'))],
            'product_branch' => [ReportColumn::code('sku', __('SKU')), ReportColumn::text('item', __('Item')), ReportColumn::text('branch', __('Station')), ReportColumn::number('quantity', __('On hand'))],
            default => [
                ReportColumn::text('region', __('Region')),
                ReportColumn::number('stations', __('Stations'), total: false),
                ReportColumn::number('items', __('Items'), total: false),
                ReportColumn::number('quantity', __('On hand'), total: false),
            ],
        };
    }

    public function totals(ReportContext $context, array $rows): array
    {
        // The regions view already ends with "Iraq and Kurdistan".
        return $context->group === 'region' ? [] : parent::totals($context, $rows);
    }

    public function rows(ReportContext $context): array
    {
        $lines = $this->stock->lines($context);
        $stations = Branch::query()->whereIn('id', collect($lines)->pluck('branch_id')->unique())->get(['id', 'name', 'governorate'])->keyBy('id');
        $regionOf = fn (int $branchId): ?string => $stations->get($branchId)?->governorate?->region();

        return match ($context->group) {
            'item' => $this->perItem($lines, $regionOf),
            'governorate' => $this->perGovernorate($lines, $stations),
            'branch', 'product_branch' => $this->perStation($lines, $stations, $context->group === 'product_branch'),
            default => $this->perRegion($lines, $regionOf),
        };
    }

    /**
     * Iraq, Kurdistan, and the whole (stations without a governorate count in the whole only).
     *
     * @param  list<array{branch_id: int, product_id: int, quantity: int}>  $lines
     * @return list<array<string, mixed>>
     */
    private function perRegion(array $lines, callable $regionOf): array
    {
        $rows = [];

        foreach ([Governorate::IRAQ, Governorate::KURDISTAN, null] as $region) {
            $in = array_filter($lines, fn (array $l) => $region === null || $regionOf($l['branch_id']) === $region);

            $rows[] = [
                'key' => $region ?? 'whole',
                'region' => Governorate::regionLabel((string) $region),
                'stations' => count(array_unique(array_column($in, 'branch_id'))),
                'items' => count(array_unique(array_column($in, 'product_id'))),
                'quantity' => array_sum(array_column($in, 'quantity')),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array{branch_id: int, product_id: int, quantity: int}>  $lines
     * @return list<array<string, mixed>>
     */
    private function perItem(array $lines, callable $regionOf): array
    {
        $items = $this->stock->items(array_column($lines, 'product_id'));
        $rows = [];

        foreach ($lines as $line) {
            $key = 'p'.$line['product_id'];
            $item = $items[$line['product_id']] ?? ['sku' => '—', 'name' => '—'];
            $rows[$key] ??= ['key' => $key, 'sku' => $item['sku'], 'item' => $item['name'], 'iraq' => 0, 'kurdistan' => 0, 'whole' => 0];

            match ($regionOf($line['branch_id'])) {
                Governorate::IRAQ => $rows[$key]['iraq'] += $line['quantity'],
                Governorate::KURDISTAN => $rows[$key]['kurdistan'] += $line['quantity'],
                default => null,
            };

            $rows[$key]['whole'] += $line['quantity'];
        }

        $rows = array_values($rows);
        usort($rows, fn (array $a, array $b) => strcmp((string) $a['sku'], (string) $b['sku']));

        return $rows;
    }

    /**
     * The company's governorates, with or without stock (like the Home page).
     *
     * @param  list<array{branch_id: int, product_id: int, quantity: int}>  $lines
     * @return list<array<string, mixed>>
     */
    private function perGovernorate(array $lines, $stations): array
    {
        $rows = [];

        foreach (CompanyContext::governorates() as $governorate) {
            $rows[$governorate->value] = ['key' => $governorate->value, 'governorate' => $governorate->getLabel(), 'region' => Governorate::regionLabel($governorate->region()), 'quantity' => 0];
        }

        foreach ($lines as $line) {
            $governorate = $stations->get($line['branch_id'])?->governorate;
            $key = $governorate === null ? 'none' : $governorate->value;
            $rows[$key] ??= ['key' => $key, 'governorate' => __('No governorate'), 'region' => '—', 'quantity' => 0];
            $rows[$key]['quantity'] += $line['quantity'];
        }

        return array_values($rows);
    }

    /**
     * @param  list<array{branch_id: int, product_id: int, quantity: int}>  $lines
     * @return list<array<string, mixed>>
     */
    private function perStation(array $lines, $stations, bool $perItem): array
    {
        $items = $perItem ? $this->stock->items(array_column($lines, 'product_id')) : [];
        $rows = [];

        foreach ($lines as $line) {
            $station = $stations->get($line['branch_id']);
            $key = $perItem ? 'p'.$line['product_id'].'-b'.$line['branch_id'] : 'b'.$line['branch_id'];
            $item = $items[$line['product_id']] ?? ['sku' => '—', 'name' => '—'];

            $rows[$key] ??= [
                'key' => $key,
                'sku' => $item['sku'],
                'item' => $item['name'],
                'branch' => $station === null ? '—' : (string) $station->name,
                'region' => $station?->governorate !== null ? Governorate::regionLabel($station->governorate->region()) : '—',
                'quantity' => 0,
            ];
            $rows[$key]['quantity'] += $line['quantity'];
        }

        return array_values($rows);
    }
}
