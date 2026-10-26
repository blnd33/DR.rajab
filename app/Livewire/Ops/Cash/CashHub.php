<?php

namespace App\Livewire\Ops\Cash;

use App\Enums\BoxTransferStatus;
use App\Enums\ClosingStatus;
use App\Livewire\Ops\Concerns\RunsStockActions;
use App\Models\BoxTransfer;
use App\Models\CashBox;
use App\Models\CashBoxClosing;
use App\Models\MoneyTransaction;
use App\Services\Money\BoxTransferService;
use App\Support\CurrentBranch;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Cashier hub for the current branch (Module 19): box balances, today's closing state,
 * money arriving from other boxes, the last movements and the "new …" actions.
 */
#[Layout('layouts::ops')]
class CashHub extends Component
{
    use RunsStockActions;

    public function mount(): void
    {
        abort_unless($this->user()->canInBranch('cash_boxes.view_any', CurrentBranch::id()), 403);
    }

    public function receive(int $transferId, BoxTransferService $transfers): void
    {
        $transfer = $this->incoming()->firstWhere('id', $transferId);

        if ($transfer !== null) {
            $this->attempt(fn () => $transfers->receive($transfer, $this->user()), __('Money received.'));
        }
    }

    /**
     * @return Collection<int, BoxTransfer>
     */
    private function incoming()
    {
        return BoxTransfer::query()->with(['fromBox.branch', 'toBox'])
            ->whereIn('to_cash_box_id', CashBox::query()->where('branch_id', CurrentBranch::id())->select('id'))
            ->where('status', BoxTransferStatus::Sent)
            ->latest('sent_at')->get();
    }

    public function render(): View
    {
        $user = $this->user();
        $branchId = (int) CurrentBranch::id();
        $can = fn (string $permission) => $branchId !== 0 && $user->canInBranch($permission, $branchId);
        $boxes = CashBox::query()->where('branch_id', $branchId)->where('is_active', true)->orderBy('type')->orderBy('currency')->get();
        $today = now(config('orthotrack.timezone'))->toDateString();

        return view('livewire.ops.cash.hub', [
            'branch' => CurrentBranch::get(),
            'boxes' => $boxes,
            'closedToday' => CashBoxClosing::query()->whereIn('cash_box_id', $boxes->pluck('id'))->where('business_date', $today)
                ->where('status', '<>', ClosingStatus::Draft)->pluck('cash_box_id')->all(),
            'incoming' => $can('cash_boxes.transfer') ? $this->incoming() : collect(),
            'recent' => MoneyTransaction::query()->with(['cashBox', 'user'])->whereIn('cash_box_id', $boxes->pluck('id'))->latest('occurred_at')->latest('id')->limit(12)->get(),
            'canSell' => $can('sales.create'),
            'canExpense' => $can('expenses.create'),
            'canExchange' => $can('currency_exchange.create') && $boxes->pluck('currency')->unique()->count() > 1,
            'canClose' => $can('cash_boxes.close_day'),
        ])->title(__('Cash desk'));
    }
}
