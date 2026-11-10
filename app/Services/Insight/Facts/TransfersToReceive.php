<?php

namespace App\Services\Insight\Facts;

use App\Enums\TransferStatus;
use App\Models\Transfer;
use App\Services\Insight\Fact;
use App\Services\Insight\FactContext;
use App\Services\Insight\FactResult;
use App\Services\Insight\FactSection;
use App\Support\Locale\Display;

/**
 * Transfers on the road to a branch where this person may receive them.
 */
class TransfersToReceive extends Fact
{
    public static function key(): string
    {
        return 'transfers-to-receive';
    }

    public function title(): string
    {
        return __('Transfers waiting to be received');
    }

    public function section(): FactSection
    {
        return FactSection::Attention;
    }

    protected function permission(): string
    {
        return 'transfers.receive';
    }

    public function build(FactContext $context): FactResult
    {
        $query = $context->scope(Transfer::query(), 'transfers.receive', 'to_branch_id')
            ->with(['fromBranch', 'toBranch'])
            ->where('status', TransferStatus::InTransit);
        $total = (clone $query)->count();
        $lines = $query->oldest('sent_at')->limit(self::MAX_LINES)->get()
            ->map(fn (Transfer $t) => self::line(
                $t->number.' · '.($t->fromBranch->code ?? '').' → '.($t->toBranch->code ?? ''),
                Display::date($t->sent_at),
            ))->all();

        return $this->result($context, self::count($total), $lines, ['transfers' => $total],
            'filament.admin.resources.transfers.index',
            tone: $total > 0 ? FactResult::WARNING : FactResult::GOOD);
    }
}
