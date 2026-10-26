<?php

namespace App\Livewire\Ops\Clinical;

use App\Enums\SurgeryStatus;
use App\Livewire\Ops\Concerns\RunsStockActions;
use App\Models\Surgery;
use App\Services\Clinical\SurgeryService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * My surgeries: drafts on this phone, drafts on the server (with what blocks them), done.
 */
#[Layout('layouts::ops')]
class SurgeryIndex extends Component
{
    use RunsStockActions;

    public function mount(): void
    {
        abort_unless($this->user()->can('viewAny', Surgery::class) || $this->user()->can('create', Surgery::class), 403);
    }

    public function render(): View
    {
        $mine = fn () => app(SurgeryService::class)->visibleTo($this->user())
            ->where(fn ($q) => $q->where('created_by', $this->user()->id)->orWhereHas('doctors', fn ($d) => $d->where('users.id', $this->user()->id)))
            ->with(['type', 'patient', 'customer'])
            ->withCount('liveItems');

        return view('livewire.ops.clinical.surgery-index', [
            'drafts' => $mine()->where('status', SurgeryStatus::Draft)->latest()->get(),
            'recent' => $mine()->where('status', '<>', SurgeryStatus::Draft)->latest('surgery_date')->latest('id')->limit(30)->get(),
            'canCreate' => $this->user()->can('create', Surgery::class),
        ])->title(__('My surgeries'));
    }
}
