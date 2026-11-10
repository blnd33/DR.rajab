<?php

namespace App\Services\Insight\Facts;

use App\Enums\KitCheckoutStatus;
use App\Models\KitCheckout;
use App\Services\Insight\Fact;
use App\Services\Insight\FactContext;
use App\Services\Insight\FactResult;
use App\Services\Insight\FactSection;

/**
 * Kits at hospitals now; overdue ones first, with how many days late.
 */
class KitsOut extends Fact
{
    public static function key(): string
    {
        return 'kits-out';
    }

    public function title(): string
    {
        return __('Kits out at hospitals');
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
        $out = $context->scope(KitCheckout::query(), 'kits.view_any')
            ->with(['kit', 'customer'])
            ->where('status', KitCheckoutStatus::Out)
            ->orderByRaw('CASE WHEN due_back_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_back_at')
            ->get();

        $now = now();
        $overdue = $out->filter(fn (KitCheckout $c) => $c->due_back_at !== null && $c->due_back_at->lessThan($now));

        $lines = $out->map(function (KitCheckout $checkout) use ($now) {
            $late = $checkout->due_back_at !== null && $checkout->due_back_at->lessThan($now);
            $days = $late ? (int) $checkout->due_back_at->diffInDays($now) : 0;
            $where = $checkout->customer->name ?? __('No hospital');

            return self::line(
                ($checkout->kit->code ?? '—').' · '.$where,
                $late ? trans_choice(':count day late|:count days late', max($days, 1), ['count' => self::count(max($days, 1))]) : __('Out'),
            );
        })->all();

        return $this->result($context, self::count($out->count()), $lines,
            ['out' => $out->count(), 'overdue' => $overdue->count()],
            'filament.admin.resources.kit-checkouts.index',
            tone: $overdue->isNotEmpty() ? FactResult::DANGER : FactResult::NEUTRAL,
            note: $overdue->isNotEmpty() ? __(':count overdue', ['count' => self::count($overdue->count())]) : null);
    }
}
