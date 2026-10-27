<?php

namespace App\Services\Reports;

use App\Enums\SalesInvoiceStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;

/**
 * Sales and profit (Module 24): revenue (after the invoice discount) minus the true cost of
 * what was sold, per month, product, branch or customer. Voided sales don't count.
 */
class SalesProfitReport extends Report
{
    public function __construct(private readonly SnapshotConverters $fx) {}

    public static function key(): string
    {
        return 'sales-profit';
    }

    public function title(): string
    {
        return __('Sales and profit');
    }

    public function description(): ?string
    {
        return __('Revenue after discounts, the true cost of the items sold and the profit.');
    }

    public function filters(): array
    {
        return ['period', 'branch', 'currency', 'group'];
    }

    public function groups(): array
    {
        return [
            'month' => __('Month'),
            'product' => __('Product'),
            'branch' => __('Branch'),
            'customer' => __('Hospital / customer'),
        ];
    }

    public function columns(ReportContext $context): array
    {
        $head = match ($context->group) {
            'product' => [ReportColumn::code('sku', __('SKU')), ReportColumn::text('product', __('Product'))],
            'branch' => [ReportColumn::text('branch', __('Branch'))],
            'customer' => [ReportColumn::text('customer', __('Hospital / customer'))],
            default => [ReportColumn::code('month', __('Month'))],
        };

        return [
            ...$head,
            ReportColumn::number('invoices', __('Invoices')),
            ReportColumn::number('quantity', __('Quantity')),
            ReportColumn::money('revenue', __('Revenue')),
            ReportColumn::money('cost', __('Cost'), profit: true),
            ReportColumn::money('profit', __('Profit'), profit: true),
            ReportColumn::percent('margin', __('Margin %'), profit: true),
        ];
    }

    public function rows(ReportContext $context): array
    {
        $items = SalesInvoiceItem::query()
            ->join('sales_invoices', 'sales_invoices.id', '=', 'sales_invoice_items.sales_invoice_id')
            ->whereNotIn('sales_invoices.status', [SalesInvoiceStatus::Draft->value, SalesInvoiceStatus::Voided->value])
            ->whereBetween('sales_invoices.issued_at', [$context->from, $context->until])
            ->when($context->branchIds !== null, fn ($q) => $q->whereIn('sales_invoices.branch_id', $context->branchIds))
            ->orderBy('sales_invoices.issued_at')
            ->select([
                'sales_invoice_items.*',
                'sales_invoices.branch_id', 'sales_invoices.customer_id', 'sales_invoices.walk_in_name', 'sales_invoices.issued_at',
                'sales_invoices.currency AS invoice_currency', 'sales_invoices.fx_snapshot_id AS invoice_snapshot_id',
                'sales_invoices.subtotal', 'sales_invoices.total',
            ])
            ->toBase()->get();

        $branches = self::names(Branch::class, $items->pluck('branch_id'));
        $customers = self::names(Customer::class, $items->pluck('customer_id'));
        $products = Product::query()->whereIn('id', $items->pluck('product_id')->unique())->get()->keyBy('id');
        $tz = config('orthotrack.timezone');
        $rows = [];
        $invoices = [];

        foreach ($items as $item) {
            $key = match ($context->group) {
                'product' => 'p'.$item->product_id,
                'branch' => 'b'.$item->branch_id,
                'customer' => 'c'.($item->customer_id ?? 0),
                default => Carbon::parse($item->issued_at)->setTimezone($tz)->format('Y-m'),
            };

            // The invoice discount is shared over its lines by value.
            $subtotal = BigDecimal::of((string) $item->subtotal);
            $line = BigDecimal::of((string) $item->line_total);
            $net = $subtotal->isZero() ? $line : $line->multipliedBy((string) $item->total)->dividedBy($subtotal, 4, RoundingMode::HalfUp);

            $revenue = $this->fx->convert((string) $net, $item->invoice_currency, $context->currency, (int) $item->invoice_snapshot_id, $context) ?? BigDecimal::zero();
            $costAmount = BigDecimal::of((string) ($item->unit_cost ?? '0'))->multipliedBy((int) $item->quantity);
            $cost = $this->fx->convert((string) $costAmount, $item->cost_currency ?? $item->invoice_currency, $context->currency,
                $item->cost_fx_snapshot_id !== null ? (int) $item->cost_fx_snapshot_id : (int) $item->invoice_snapshot_id, $context) ?? BigDecimal::zero();
            $product = $products->get((int) $item->product_id);

            $rows[$key] ??= [
                'key' => $key,
                'month' => $key,
                'sku' => $product->sku ?? '—',
                'product' => $product->name ?? '—',
                'branch' => $branches[(int) $item->branch_id] ?? '—',
                'customer' => $item->customer_id !== null ? ($customers[(int) $item->customer_id] ?? '—') : __('Walk-in'),
                'quantity' => 0,
                'revenue' => '0',
                'cost' => '0',
            ];
            $invoices[$key][$item->sales_invoice_id] = true;
            $rows[$key]['quantity'] += (int) $item->quantity;
            $rows[$key]['revenue'] = self::money(BigDecimal::of($rows[$key]['revenue'])->plus($revenue));
            $rows[$key]['cost'] = self::money(BigDecimal::of($rows[$key]['cost'])->plus($cost));
        }

        foreach ($rows as $key => $row) {
            $profit = BigDecimal::of($row['revenue'])->minus($row['cost']);
            $rows[$key]['invoices'] = count($invoices[$key] ?? []);
            $rows[$key]['profit'] = self::money($profit);
            $rows[$key]['margin'] = self::percent($profit, BigDecimal::of($row['revenue']));
        }

        return array_values($rows);
    }

    public function totals(ReportContext $context, array $rows): array
    {
        $totals = parent::totals($context, $rows);

        if (isset($totals['profit'], $totals['revenue'])) {
            $totals['margin'] = (string) self::percent(BigDecimal::of($totals['profit']), BigDecimal::of($totals['revenue']));
        }

        // An invoice can sit in more than one row (per product); count each once.
        $totals['invoices'] = (string) SalesInvoice::query()
            ->whereNotIn('status', [SalesInvoiceStatus::Draft->value, SalesInvoiceStatus::Voided->value])
            ->whereBetween('issued_at', [$context->from, $context->until])
            ->when($context->branchIds !== null, fn ($q) => $q->whereIn('branch_id', $context->branchIds))
            ->count();

        return $totals;
    }
}
