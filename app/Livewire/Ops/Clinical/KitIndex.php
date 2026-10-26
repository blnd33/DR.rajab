<?php

namespace App\Livewire\Ops\Clinical;

use App\Enums\KitStatus;
use App\Livewire\Ops\Concerns\RunsStockActions;
use App\Models\Kit;
use App\Services\Assets\AssetLookup;
use App\Support\CurrentBranch;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Kits of the current branch (and its kits out at hospitals); scan a kit label to open it.
 */
#[Layout('layouts::ops')]
class KitIndex extends Component
{
    use RunsStockActions;

    public function mount(): void
    {
        abort_unless($this->user()->can('viewAny', Kit::class), 403);
    }

    public function handleScan(string $raw): void
    {
        $kit = app(AssetLookup::class)->kit($raw);

        if ($kit === null || ! $this->user()->can('view', $kit)) {
            $this->dispatch('scan-feedback', type: 'error');
            $this->dispatch('toast', type: 'error', message: __('No kit with this code.'));

            return;
        }

        $this->dispatch('scan-feedback', type: 'success');
        $this->redirectRoute('ops.kits.show', $kit, navigate: true);
    }

    public function render(): View
    {
        $branchId = (int) CurrentBranch::id();

        $kits = Kit::query()->with(['type', 'customer', 'currentCheckout'])
            ->where('branch_id', $branchId)
            ->where('status', '<>', KitStatus::Retired)
            ->orderBy('code')
            ->get();

        return view('livewire.ops.clinical.kit-index', [
            'branch' => CurrentBranch::get(),
            'kits' => $kits,
        ])->title(__('Kits'));
    }
}
