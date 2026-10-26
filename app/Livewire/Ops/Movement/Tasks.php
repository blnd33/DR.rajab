<?php

namespace App\Livewire\Ops\Movement;

use App\Enums\StockCountStatus;
use App\Enums\StockRequestStatus;
use App\Enums\TransferStatus;
use App\Enums\WriteOffStatus;
use App\Livewire\Ops\Concerns\RunsStockActions;
use App\Models\StockCount;
use App\Models\StockRequest;
use App\Models\Transfer;
use App\Models\WriteOff;
use App\Support\CurrentBranch;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Tasks hub for the current branch: transfers to receive and to send, stock requests,
 * counts in progress, write-offs waiting — plus the "new …" actions the user may start.
 */
#[Layout('layouts::ops')]
class Tasks extends Component
{
    use RunsStockActions;

    public function render(): View
    {
        $user = $this->user();
        $branchId = (int) CurrentBranch::id();
        $can = fn (string $permission) => $branchId !== 0 && $user->canInBranch($permission, $branchId);

        return view('livewire.ops.movement.tasks', [
            'branch' => CurrentBranch::get(),
            'incoming' => $can('transfers.receive')
                ? Transfer::query()->with('fromBranch')->withCount('items')->where('to_branch_id', $branchId)->where('status', TransferStatus::InTransit)->latest('sent_at')->get()
                : collect(),
            'toSend' => $can('transfers.send')
                ? Transfer::query()->with('toBranch')->withCount('items')->where('from_branch_id', $branchId)->where('status', TransferStatus::Draft)->latest()->get()
                : collect(),
            'outgoing' => $can('transfers.view_any')
                ? Transfer::query()->with('toBranch')->withCount('items')->where('from_branch_id', $branchId)->where('status', TransferStatus::InTransit)->latest('sent_at')->limit(10)->get()
                : collect(),
            'requests' => $can('stock_requests.view_any')
                ? StockRequest::query()->with('preferredSourceBranch')->withCount('items')->where('requesting_branch_id', $branchId)
                    ->whereIn('status', [StockRequestStatus::Pending, StockRequestStatus::Approved, StockRequestStatus::PartiallyApproved])->latest()->get()
                : collect(),
            'counts' => $can('stock_counts.view_any')
                ? StockCount::query()->where('branch_id', $branchId)->whereIn('status', [StockCountStatus::InProgress, StockCountStatus::Submitted])->latest()->get()
                : collect(),
            'writeOffs' => $can('write_offs.view_any') || $can('write_offs.report')
                ? WriteOff::query()->withCount('items')->where('branch_id', $branchId)->where('status', WriteOffStatus::Pending)->latest()->get()
                : collect(),
            'canCreateTransfer' => $can('transfers.create'),
            'canRequest' => $can('stock_requests.create'),
            'canCount' => $can('stock_counts.start'),
            'canWriteOff' => $can('write_offs.report'),
            'canReturn' => $can('returns.customer'),
        ])->title(__('Tasks'));
    }
}
