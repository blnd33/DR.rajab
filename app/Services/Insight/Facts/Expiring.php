<?php

namespace App\Services\Insight\Facts;

use App\Services\Insight\Fact;
use App\Services\Insight\FactContext;
use App\Services\Insight\FactResult;
use App\Services\Insight\FactSection;
use App\Services\Stock\StockQuery;

/**
 * Items on hand per expiry level (the levels and colours set in Settings): expired first, then
 * each level with an end. The open-ended "fine" level is left out.
 */
class Expiring extends Fact
{
    public function __construct(private readonly StockQuery $stock) {}

    public static function key(): string
    {
        return 'expiring';
    }

    public function title(): string
    {
        return __('Expiry');
    }

    public function section(): FactSection
    {
        return FactSection::Stock;
    }

    protected function permission(): string
    {
        return 'stock.view';
    }

    public function build(FactContext $context): FactResult
    {
        $expired = 0;
        $soon = 0;
        $lines = [];

        foreach ($this->stock->expiryCounts($context->branchIds('stock.view')) as $count) {
            $level = $count['level'];

            if ($level->is_expired_level) {
                $expired += $count['total'];
            } elseif ($level->max_days !== null) {
                $soon += $count['total'];
            } else {
                continue;
            }

            if ($count['total'] > 0) {
                $label = $level->is_expired_level ? $level->name : $level->name.' ('.__(':range days left', ['range' => $level->rangeLabel()]).')';
                $lines[] = self::line($label, self::count($count['total']));
            }
        }

        return $this->result($context, self::count($expired + $soon), $lines,
            ['expired' => $expired, 'soon' => $soon],
            'filament.admin.pages.reports.expiry',
            tone: $expired > 0 ? FactResult::DANGER : ($soon > 0 ? FactResult::WARNING : FactResult::GOOD));
    }
}
