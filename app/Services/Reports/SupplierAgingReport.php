<?php

namespace App\Services\Reports;

use App\Enums\PaymentStatus;
use App\Enums\PurchaseStatus;
use App\Models\Purchase;
use App\Models\Supplier;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;

/**
 * What we owe each supplier and how late it is (Module 7 / 24): open confirmed invoices by
 * days past their due date, converted with each invoice's own rates.
 */
class SupplierAgingReport extends Report
{
    public function __construct(private readonly SnapshotConverters $fx) {}

    public static function key(): string
    {
        return 'supplier-aging';
    }

    public function title(): string
    {
        return __('Supplier aging');
    }

    public function description(): ?string
    {
        return __('Unpaid supplier invoices today, by how many days they are past due.');
    }

    public function filters(): array
    {
        return ['branch', 'currency'];
    }

    public function defaultFrom(): Carbon
    {
        return now(config('orthotrack.timezone'))->startOfDay();
    }

    public function columns(ReportContext $context): array
    {
        return [
            ReportColumn::code('code', __('Code')),
            ReportColumn::text('supplier', __('Supplier')),
            ReportColumn::number('invoices', __('Invoices')),
            ReportColumn::money('not_due', __('Not due yet')),
            ReportColumn::money('d30', __('1–30 days')),
            ReportColumn::money('d60', __('31–60 days')),
            ReportColumn::money('d90', __('61–90 days')),
            ReportColumn::money('d90plus', __('Over 90 days')),
            ReportColumn::money('open', __('Total open')),
        ];
    }

    public function rows(ReportContext $context): array
    {
        $today = now(config('orthotrack.timezone'))->startOfDay();
        $purchases = Purchase::query()
            ->where('status', PurchaseStatus::Confirmed)
            ->where('payment_status', '<>', PaymentStatus::Paid)
            ->when($context->branchIds !== null, fn ($q) => $q->whereIn('branch_id', $context->branchIds))
            ->get();

        $suppliers = Supplier::query()->whereIn('id', $purchases->pluck('supplier_id')->unique())->get()->keyBy('id');
        $rows = [];

        foreach ($purchases as $purchase) {
            $open = BigDecimal::of($purchase->total)->minus($purchase->paid_amount);

            if (! $open->isPositive()) {
                continue;
            }

            $amount = $this->fx->convert((string) $open, $purchase->currency, $context->currency, $purchase->fx_snapshot_id, $context) ?? BigDecimal::zero();
            $late = $purchase->due_date !== null ? (int) $purchase->due_date->copy()->startOfDay()->diffInDays($today, false) : 0;
            $bucket = match (true) {
                $late <= 0 => 'not_due',
                $late <= 30 => 'd30',
                $late <= 60 => 'd60',
                $late <= 90 => 'd90',
                default => 'd90plus',
            };
            $supplier = $suppliers->get($purchase->supplier_id);
            $key = 's'.$purchase->supplier_id;

            $rows[$key] ??= ['key' => $key, 'code' => $supplier->code ?? '—', 'supplier' => $supplier->name ?? '—', 'invoices' => 0,
                'not_due' => '0', 'd30' => '0', 'd60' => '0', 'd90' => '0', 'd90plus' => '0', 'open' => '0'];
            $rows[$key]['invoices']++;
            $rows[$key][$bucket] = self::money(BigDecimal::of($rows[$key][$bucket])->plus($amount));
            $rows[$key]['open'] = self::money(BigDecimal::of($rows[$key]['open'])->plus($amount));
        }

        uasort($rows, fn ($a, $b) => BigDecimal::of($b['open'])->compareTo($a['open']));

        return array_values($rows);
    }
}
