<?php

namespace App\Livewire\Ops\Clinical;

use App\Enums\SurgeryStatus;
use App\Livewire\Ops\Concerns\RunsStockActions;
use App\Models\Surgery;
use App\Services\Clinical\SurgeryService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * One surgery on the phone: what blocks a draft, complete it, add sticker photos.
 */
#[Layout('layouts::ops')]
class SurgeryShow extends Component
{
    use RunsStockActions;
    use WithFileUploads;

    public Surgery $surgery;

    public string $overrideReason = '';

    public string $cancelReason = '';

    /** @var list<TemporaryUploadedFile> */
    #[Validate(['photos.*' => 'image|max:8192'])]
    public array $photos = [];

    public function mount(Surgery $surgery): void
    {
        abort_unless($this->user()->can('view', $surgery), 403);
        $this->surgery = $surgery;
    }

    public function complete(SurgeryService $surgeries): void
    {
        $done = $this->attempt(fn () => $surgeries->complete($this->surgery, $this->user(), $this->overrideReason ?: null), __('Surgery completed.'));

        if ($done !== null) {
            $this->surgery->refresh();
        }
    }

    public function updatedPhotos(SurgeryService $surgeries): void
    {
        $this->validate();

        foreach ($this->photos as $photo) {
            $this->attempt(fn () => $surgeries->attachPhoto($this->surgery, $photo, $this->user()));
        }

        $this->photos = [];
        $this->dispatch('toast', type: 'success', message: __('Photos added.'));
    }

    public function cancelDraft(SurgeryService $surgeries): void
    {
        $done = $this->attempt(fn () => $surgeries->cancel($this->surgery, $this->cancelReason ?: __('Draft discarded'), $this->user()), __('Draft cancelled.'));

        if ($done !== null) {
            $this->surgery->refresh();
        }
    }

    public function render(): View
    {
        $this->surgery->load(['type', 'patient', 'customer', 'branch', 'doctors', 'kitCheckout.kit', 'items.product', 'items.stockUnit', 'items.stockBatch', 'attachments']);
        $user = $this->user();

        return view('livewire.ops.clinical.surgery-show', [
            'canComplete' => $this->surgery->status === SurgeryStatus::Draft && $user->canInBranch('surgeries.create', $this->surgery->branch_id),
            'canPhoto' => $this->surgery->status !== SurgeryStatus::Cancelled && $user->canInBranch('surgeries.create', $this->surgery->branch_id),
            'canCancelDraft' => $this->surgery->status === SurgeryStatus::Draft && ($this->surgery->created_by === $user->id || $user->canInBranch('surgeries.cancel', $this->surgery->branch_id)),
        ])->title($this->surgery->number);
    }
}
