<?php

namespace App\Services\Insight\Facts;

use App\Enums\CheckoutItemOutcome;
use App\Models\KitCheckout;
use App\Models\KitCheckoutItem;
use App\Services\Insight\Fact;
use App\Services\Insight\FactContext;
use App\Services\Insight\FactResult;
use App\Services\Insight\FactSection;

/**
 * Implants missing after a kit came back (neither returned nor used in a surgery), not yet
 * resolved.
 */
class UnaccountedImplants extends Fact
{
    public static function key(): string
    {
        return 'unaccounted-implants';
    }

    public function title(): string
    {
        return __('Missing after a return');
    }

    public function section(): FactSection
    {
        return FactSection::Kits;
    }

    protected function permission(): string
    {
        return 'kits.view_any';
    }

    public function build(FactContext $context): FactResult
    {
        $items = KitCheckoutItem::query()
            ->with(['product', 'checkout.kit'])
            ->where('outcome', CheckoutItemOutcome::Unaccounted)
            ->where('qty_unaccounted', '>', 0)
            ->whereIn('kit_checkout_id', $context->scope(KitCheckout::query(), 'kits.view_any')->select('id'))
            ->latest('id')
            ->get();

        $total = (int) $items->sum('qty_unaccounted');
        $lines = $items->map(fn (KitCheckoutItem $item) => self::line(
            ($item->product->sku ?? '—').' · '.($item->checkout->kit->code ?? '—'),
            self::count((int) $item->qty_unaccounted),
        ))->all();

        return $this->result($context, self::count($total), $lines, ['implants' => $total],
            'filament.admin.resources.kit-checkouts.index',
            tone: $total > 0 ? FactResult::DANGER : FactResult::NEUTRAL);
    }
}
