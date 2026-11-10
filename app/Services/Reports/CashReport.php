<?php

namespace App\Services\Reports;

use App\Enums\MoneyTransactionType;
use App\Models\CashBox;
use App\Models\MoneyTransaction;
use Brick\Math\BigDecimal;

/**
 * Cash and bank in a period (Module 19 / 24): per box the balance at the start, money in,
 * money out, closing differences and the balance at the end — in the box's own currency,
 * plus the end balance in the display currency at today's rates.
 */
class CashReport extends Report
{
    public function __construct(private readonly SnapshotConverters $fx) {}

    public static function key(): string
    {
        return 'cash';
    }

    public function title(): string
    {
        return __('Cash report');
    }

    public function description(): ?string
    {
        return __('Money in and out of every box in the period, with the daily closing differences.');
    }

    public function columns(ReportContext $context): array
    {
        return [
            ReportColumn::text('box', __('Box')),
            ReportColumn::code('currency', __('Currency')),
            ReportColumn::moneyIn('opening', __('At the start'), 'currency'),
            ReportColumn::moneyIn('in', __('Money in'), 'currency'),
            ReportColumn::moneyIn('out', __('Money out'), 'currency'),
            ReportColumn::moneyIn('differences', __('Closing differences'), 'currency'),
            ReportColumn::moneyIn('closing', __('At the end'), 'currency'),
            ReportColumn::money('closing_display', __('At the end (display currency)')),
        ];
    }

    public function rows(ReportContext $context): array
    {
        $boxes = CashBox::query()->with('branch')
            ->when($context->branchIds !== null, fn ($q) => $q->whereIn('branch_id', $context->branchIds))
            ->orderByRaw('branch_id IS NULL')->orderBy('branch_id')->orderBy('currency')
            ->get();

        $rows = [];

        foreach ($boxes as $box) {
            $opening = (string) (MoneyTransaction::query()->where('cash_box_id', $box->id)->where('occurred_at', '<', $context->bounds()[0])
                ->orderByDesc('occurred_at')->orderByDesc('id')->value('balance_after') ?? '0');
            $period = MoneyTransaction::query()->where('cash_box_id', $box->id)->whereBetween('occurred_at', $context->bounds());

            $in = (string) ((clone $period)->where('amount', '>', 0)->where('type', '<>', MoneyTransactionType::ClosingAdjustment)->sum('amount') ?: '0');
            $out = (string) ((clone $period)->where('amount', '<', 0)->where('type', '<>', MoneyTransactionType::ClosingAdjustment)->sum('amount') ?: '0');
            $differences = (string) ((clone $period)->where('type', MoneyTransactionType::ClosingAdjustment)->sum('amount') ?: '0');
            $closing = BigDecimal::of($opening)->plus($in)->plus($out)->plus($differences);

            if ($closing->isZero() && BigDecimal::of($opening)->isZero() && ! (clone $period)->exists()) {
                continue;
            }

            $rows[] = [
                'key' => 'box'.$box->id,
                'box' => $box->label(),
                'currency' => $box->currency,
                'opening' => self::money(BigDecimal::of($opening)),
                'in' => self::money(BigDecimal::of($in)),
                'out' => self::money(BigDecimal::of($out)),
                'differences' => self::money(BigDecimal::of($differences)),
                'closing' => self::money($closing),
                'closing_display' => self::money($this->fx->convert((string) $closing, $box->currency, $context->currency, null, $context) ?? BigDecimal::zero()),
            ];
        }

        return $rows;
    }
}
