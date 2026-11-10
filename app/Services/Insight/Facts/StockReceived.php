<?php

namespace App\Services\Insight\Facts;

use App\Enums\MovementType;
use App\Models\StockMovement;
use App\Services\Insight\Fact;
use App\Services\Insight\FactContext;
use App\Services\Insight\FactResult;
use App\Services\Insight\FactSection;

/**
 * Items that came into stock in the period: purchases received, opening stock, Excel imports
 * (transfers between branches are not new stock).
 */
class StockReceived extends Fact
{
    private const TYPES = [MovementType::PurchaseReceipt, MovementType::OpeningBalance, MovementType::Import];

    public static function key(): string
    {
        return 'stock-received';
    }

    public function title(): string
    {
        return __('Stock received');
    }

    public function section(): FactSection
    {
        return FactSection::Day;
    }

    protected function permission(): string
    {
        return 'stock.view';
    }

    public function usesPeriod(): bool
    {
        return true;
    }

    public function build(FactContext $context): FactResult
    {
        $byType = $context->scope(StockMovement::query(), 'stock.view', 'to_branch_id')
            ->whereIn('type', array_map(fn (MovementType $t) => $t->value, self::TYPES))
            ->whereBetween('occurred_at', $context->bounds())
            ->toBase()->groupBy('type')->selectRaw('type, SUM(quantity) AS qty')->pluck('qty', 'type');

        $total = (int) $byType->sum();
        $lines = [];

        foreach (self::TYPES as $type) {
            if ((int) ($byType[$type->value] ?? 0) > 0) {
                $lines[] = self::line($type->getLabel(), self::count((int) $byType[$type->value]));
            }
        }

        return $this->result($context, self::count($total), $lines, ['items' => $total], 'filament.admin.resources.stock-movements.index');
    }
}
