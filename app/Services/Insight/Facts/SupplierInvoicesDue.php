<?php

namespace App\Services\Insight\Facts;

use App\Enums\PaymentStatus;
use App\Enums\PurchaseStatus;
use App\Models\Purchase;
use App\Services\Insight\Fact;
use App\Services\Insight\FactContext;
use App\Services\Insight\FactResult;
use App\Services\Insight\FactSection;
use App\Services\Reports\SnapshotConverters;
use App\Support\Locale\Display;
use Brick\Math\BigDecimal;

/**
 * Supplier invoices not fully paid that are past due or due within the next 7 days.
 */
class SupplierInvoicesDue extends Fact
{
    public const DAYS_AHEAD = 7;

    public static function key(): string
    {
        return 'supplier-invoices-due';
    }

    public function title(): string
    {
        return __('Supplier invoices due');
    }

    public function section(): FactSection
    {
        return FactSection::Money;
    }

    protected function permission(): string
    {
        return 'purchases.view_any';
    }

    public function build(FactContext $context): FactResult
    {
        $today = now(config('orthotrack.timezone'))->startOfDay();
        $purchases = $context->scope(Purchase::query(), 'purchases.view_any')
            ->with('supplier')
            ->where('status', PurchaseStatus::Confirmed)
            ->where('payment_status', '<>', PaymentStatus::Paid)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', $today->copy()->addDays(self::DAYS_AHEAD)->toDateString())
            ->orderBy('due_date')
            ->get();

        $fx = app(SnapshotConverters::class);
        $missing = false;
        $total = BigDecimal::zero();
        $overdue = 0;
        $lines = [];

        foreach ($purchases as $purchase) {
            $open = BigDecimal::of((string) $purchase->total)->minus((string) ($purchase->paid_amount ?? '0'));
            $amount = $fx->convert((string) $open, $purchase->currency, $context->currency, $purchase->fx_snapshot_id);
            $missing = $missing || $amount === null;
            $total = $total->plus($amount ?? BigDecimal::zero());
            $late = $purchase->due_date->toDateString() < $today->toDateString();
            $overdue += $late ? 1 : 0;

            $lines[] = self::line(
                trim(($purchase->supplier->name ?? '').' · '.Display::date($purchase->due_date)).($late ? ' · '.__('Overdue') : ''),
                self::money($open, $purchase->currency),
            );
        }

        return $this->result($context, self::money($total, $context->currency), $lines,
            ['total' => self::amount($total), 'invoices' => $purchases->count(), 'overdue' => $overdue],
            'filament.admin.resources.purchases.index',
            tone: $overdue > 0 ? FactResult::DANGER : ($purchases->isNotEmpty() ? FactResult::WARNING : FactResult::NEUTRAL),
            note: $missing ? __('Some amounts could not be converted — set the exchange rates.') : null);
    }
}
