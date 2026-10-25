<?php

namespace App\Livewire\Ops\Stock;

use App\Models\StockUnit;
use App\Models\User;
use App\Services\Stock\SerialHistory;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * One serial unit with its full history (Module 4 serial history).
 */
#[Layout('layouts::ops')]
class UnitShow extends Component
{
    public StockUnit $unit;

    public function mount(StockUnit $unit): void
    {
        /** @var User $user */
        $user = Auth::user();
        abort_unless($user->can('view', $unit), 403);

        $this->unit = $unit;
    }

    public function render(SerialHistory $history): View
    {
        $this->unit->loadMissing(['product', 'branch', 'location', 'supplier']);

        return view('livewire.ops.stock.unit', [
            'movements' => $history->for($this->unit),
            'history' => $history,
        ])->title($this->unit->serial);
    }
}
