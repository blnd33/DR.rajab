<?php

namespace App\Livewire\Ops\Movement;

use App\Livewire\Ops\Concerns\RunsStockActions;
use App\Models\Branch;
use App\Services\Movement\TransferService;
use App\Support\CurrentBranch;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Start a transfer from the current branch: pick the destination, then scan on the next screen.
 */
#[Layout('layouts::ops')]
class TransferCreate extends Component
{
    use RunsStockActions;

    public ?int $toBranchId = null;

    public string $notes = '';

    public function mount(): void
    {
        abort_unless($this->user()->canInBranch('transfers.create', CurrentBranch::id()), 403);
    }

    public function create(TransferService $transfers): void
    {
        $this->validate(['toBranchId' => ['required', 'integer']], [], ['toBranchId' => __('Send to')]);

        $from = CurrentBranch::get();
        $to = Branch::query()->active()->find($this->toBranchId);

        if ($from === null || $to === null) {
            return;
        }

        $transfer = $this->attempt(fn () => $transfers->createDraft($from, $to, $this->user(), $this->notes ?: null));

        if ($transfer !== null) {
            $this->redirectRoute('ops.transfers.show', $transfer, navigate: true);
        }
    }

    public function render(): View
    {
        return view('livewire.ops.movement.transfer-create', [
            'from' => CurrentBranch::get(),
            'branches' => Branch::query()->active()->where('id', '<>', CurrentBranch::id())->orderBy('code')->get(),
        ])->title(__('New transfer'));
    }
}
