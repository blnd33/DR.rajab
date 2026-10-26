<?php

namespace App\Services\Assets;

use App\Enums\KitCheckoutStatus;
use App\Models\KitCheckout;
use App\Notifications\DocumentEvent;
use App\Services\Movement\Notifier;
use App\Support\Msg;

/**
 * Daily reminder (in stock:alerts) for kits out at hospitals longer than the overdue-kit
 * days setting (Module 18 / 25, P3-9): one notification per branch with the count.
 */
class OverdueKitNotifier
{
    public function __construct(private readonly Notifier $notifier) {}

    /**
     * @return int branches notified
     */
    public function notify(): int
    {
        $overdue = KitCheckout::query()
            ->where('status', KitCheckoutStatus::Out)
            ->where('due_back_at', '<', now())
            ->with(['kit', 'customer'])
            ->get()
            ->groupBy('branch_id');

        foreach ($overdue as $branchId => $checkouts) {
            /** @var KitCheckout $first */
            $first = $checkouts->first();

            $this->notifier->toBranch('kits.checkout', (int) $branchId, new DocumentEvent(
                'kits.overdue',
                Msg::key('Overdue kits: :count'),
                ['count' => $checkouts->count()],
                Msg::key(':kit at :hospital since :date (and others if more than one).'),
                ['kit' => $first->kit->code, 'hospital' => $first->customer->code, 'date' => $first->checked_out_at->toDateString()],
                route('filament.admin.resources.kit-checkouts.index'),
                'heroicon-o-clock',
            ));
        }

        return $overdue->count();
    }
}
