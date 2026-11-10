<?php

namespace App\Services\Insight\Facts;

use App\Enums\TransferStatus;
use App\Models\Transfer;
use App\Services\Insight\Fact;
use App\Services\Insight\FactContext;
use App\Services\Insight\FactResult;
use App\Services\Insight\FactSection;

/**
 * Transfers sent and received in the period (either branch in the user's scope).
 */
class TransfersMoved extends Fact
{
    public static function key(): string
    {
        return 'transfers-moved';
    }

    public function title(): string
    {
        return __('Transfers sent and received');
    }

    public function section(): FactSection
    {
        return FactSection::Day;
    }

    protected function permission(): string
    {
        return 'transfers.view_any';
    }

    public function usesPeriod(): bool
    {
        return true;
    }

    public function build(FactContext $context): FactResult
    {
        $transfers = fn () => $context->scope(Transfer::query(), 'transfers.view_any', ['from_branch_id', 'to_branch_id'])
            ->where('status', '<>', TransferStatus::Cancelled);
        $sent = $transfers()->whereBetween('sent_at', $context->bounds())->count();
        $received = $transfers()->whereBetween('received_at', $context->bounds())->count();

        return $this->result($context,
            __(':sent sent · :received received', ['sent' => self::count($sent), 'received' => self::count($received)]),
            [],
            ['sent' => $sent, 'received' => $received],
            'filament.admin.resources.transfers.index');
    }
}
