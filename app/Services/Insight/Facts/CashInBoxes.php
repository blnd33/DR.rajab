<?php

namespace App\Services\Insight\Facts;

use App\Models\CashBox;
use App\Services\Insight\Fact;
use App\Services\Insight\FactContext;
use App\Services\Insight\FactResult;
use App\Services\Insight\FactSection;
use App\Services\Reports\SnapshotConverters;
use Brick\Math\BigDecimal;

/**
 * Money in each open cash box and bank account now; the total at today's rates.
 */
class CashInBoxes extends Fact
{
    public static function key(): string
    {
        return 'cash-in-boxes';
    }

    public function title(): string
    {
        return __('Cash in the boxes');
    }

    public function section(): FactSection
    {
        return FactSection::Money;
    }

    protected function permission(): string
    {
        return 'cash_boxes.view_any';
    }

    public function build(FactContext $context): FactResult
    {
        $boxes = $context->scope(CashBox::query(), 'cash_boxes.view_any')
            ->where('is_active', true)->orderByDesc('current_balance')->get();

        $fx = app(SnapshotConverters::class);
        $missing = false;
        $total = BigDecimal::zero();

        foreach ($boxes as $box) {
            $amount = $fx->convert((string) $box->current_balance, $box->currency, $context->currency, null);
            $missing = $missing || $amount === null;
            $total = $total->plus($amount ?? BigDecimal::zero());
        }

        $lines = $boxes->map(fn (CashBox $box) => self::line($box->name, self::money((string) $box->current_balance, $box->currency)))->all();

        return $this->result($context, self::money($total, $context->currency), $lines,
            ['total' => self::amount($total), 'boxes' => $boxes->count()],
            'filament.admin.resources.cash-boxes.index',
            note: $missing ? __('Some amounts could not be converted — set the exchange rates.') : __('At today\'s rates'));
    }
}
