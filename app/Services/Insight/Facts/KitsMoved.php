<?php

namespace App\Services\Insight\Facts;

use App\Models\KitCheckout;
use App\Services\Insight\Fact;
use App\Services\Insight\FactContext;
use App\Services\Insight\FactResult;
use App\Services\Insight\FactSection;

/**
 * Kits sent to hospitals and kits back from hospitals in the period.
 */
class KitsMoved extends Fact
{
    public static function key(): string
    {
        return 'kits-moved';
    }

    public function title(): string
    {
        return __('Kits sent and returned');
    }

    public function section(): FactSection
    {
        return FactSection::Day;
    }

    protected function permission(): string
    {
        return 'kits.view_any';
    }

    public function usesPeriod(): bool
    {
        return true;
    }

    public function build(FactContext $context): FactResult
    {
        $checkouts = fn () => $context->scope(KitCheckout::query(), 'kits.view_any');
        $sent = $checkouts()->whereBetween('checked_out_at', $context->bounds())->count();
        $returned = $checkouts()->whereBetween('returned_at', $context->bounds())->count();

        return $this->result($context,
            __(':sent sent · :back returned', ['sent' => self::count($sent), 'back' => self::count($returned)]),
            [],
            ['sent' => $sent, 'returned' => $returned],
            'filament.admin.resources.kit-checkouts.index');
    }
}
