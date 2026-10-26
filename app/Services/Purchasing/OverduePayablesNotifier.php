<?php

namespace App\Services\Purchasing;

use App\Enums\PaymentStatus;
use App\Enums\PurchaseStatus;
use App\Models\Purchase;
use App\Notifications\DocumentEvent;
use App\Services\Movement\Notifier;
use App\Support\Msg;

/**
 * Daily reminder (in money:daily) for confirmed supplier invoices past their due date
 * and not fully paid: one notification per branch to the people who pay suppliers.
 */
class OverduePayablesNotifier
{
    public function __construct(private readonly Notifier $notifier) {}

    /**
     * @return int branches notified
     */
    public function notify(): int
    {
        $overdue = Purchase::query()
            ->where('status', PurchaseStatus::Confirmed)
            ->where('payment_status', '<>', PaymentStatus::Paid)
            ->whereDate('due_date', '<', today())
            ->with('supplier')
            ->orderBy('due_date')
            ->get()
            ->groupBy('branch_id');

        foreach ($overdue as $branchId => $purchases) {
            /** @var Purchase $first */
            $first = $purchases->first();

            $this->notifier->toBranch('supplier_payments.create', (int) $branchId, new DocumentEvent(
                'purchases.overdue',
                Msg::key('Overdue supplier invoices: :count'),
                ['count' => $purchases->count()],
                Msg::key(':supplier invoice :number was due :date (and others if more than one).'),
                ['supplier' => $first->supplier->name, 'number' => $first->supplier_invoice_number, 'date' => $first->due_date?->toDateString()],
                route('filament.admin.resources.purchases.index', ['tableFilters' => ['overdue' => ['isActive' => true]]]),
                'heroicon-o-banknotes',
            ));
        }

        return $overdue->count();
    }
}
