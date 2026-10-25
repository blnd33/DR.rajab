<?php

namespace App\Livewire\Ops;

use App\Models\User;
use App\Services\Stock\RecallSearch;
use App\Support\Locale\DigitConverter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Recall by lot on the phone (stock.recall): where is every item of this lot now?
 */
#[Layout('layouts::ops')]
class Recall extends Component
{
    #[Url]
    public string $lot = '';

    public function mount(): void
    {
        /** @var User $user */
        $user = Auth::user();
        abort_unless($user->can('stock.recall'), 403);
    }

    public function handleScan(string $raw, string $source = 'manual'): void
    {
        $this->lot = trim((string) DigitConverter::toWestern($raw));
    }

    public function render(RecallSearch $recall): View
    {
        $lot = trim((string) DigitConverter::toWestern($this->lot));

        return view('livewire.ops.recall', [
            'results' => $lot === '' ? null : $recall->search($lot),
        ])->title(__('Recall search'));
    }
}
