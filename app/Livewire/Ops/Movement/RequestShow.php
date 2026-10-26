<?php

namespace App\Livewire\Ops\Movement;

use App\Enums\StockRequestStatus;
use App\Livewire\Ops\Concerns\RunsStockActions;
use App\Models\StockRequest;
use App\Services\Movement\StockRequestService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * A stock request with its decision and the transfers it created.
 */
#[Layout('layouts::ops')]
class RequestShow extends Component
{
    use RunsStockActions;

    public StockRequest $stockRequest;

    public function mount(StockRequest $stockRequest): void
    {
        $user = $this->user();
        abort_unless(
            $stockRequest->requested_by === $user->id
            || $user->canInBranch('stock_requests.view_any', $stockRequest->requesting_branch_id)
            || $user->canInBranch('stock_requests.approve', $stockRequest->requesting_branch_id),
            403,
        );

        $this->stockRequest = $stockRequest;
    }

    public function cancel(StockRequestService $requests): void
    {
        if ($this->attempt(fn () => $requests->cancel($this->stockRequest, $this->user()), __('Request cancelled.')) !== null) {
            $this->stockRequest->refresh();
        }
    }

    public function render(): View
    {
        $this->stockRequest->load(['items.product', 'items.sourceBranch', 'transfers.fromBranch', 'requestingBranch', 'requester', 'reviewer']);

        return view('livewire.ops.movement.request-show', [
            'canCancel' => $this->stockRequest->status === StockRequestStatus::Pending && $this->stockRequest->requested_by === $this->user()->id,
        ])->title($this->stockRequest->number);
    }
}
