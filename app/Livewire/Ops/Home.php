<?php

namespace App\Livewire\Ops;

use App\Enums\Panel;
use App\Models\LowStock;
use App\Models\User;
use App\Services\Stock\StockQuery;
use App\Support\CurrentBranch;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Ops home: greeting, current branch, the big Scan button, stock alerts for the branch
 * (expired / next expiry level, below minimum) and quick actions. Later phases add
 * surgery, transfer, kit and cash tiles.
 */
#[Layout('layouts::ops')]
class Home extends Component
{
    public function render(): View
    {
        /** @var User $user */
        $user = Auth::user();

        $branchId = CurrentBranch::id();
        $canStock = $branchId !== null && $user->canInBranch('stock.view', $branchId);

        return view('livewire.ops.home', [
            'user' => $user,
            'branch' => CurrentBranch::get(),
            'expiry' => $canStock ? collect(app(StockQuery::class)->expiryCounts([$branchId]))->take(2)->values() : collect(),
            'lowStock' => $canStock ? LowStock::query()->where('branch_id', $branchId)->count() : null,
            'canReceive' => $branchId !== null && $user->canInBranch('stock.receive', $branchId),
            'canRecall' => $user->can('stock.recall'),
            'canStock' => $canStock,
            'role' => $user->primaryRole(),
            'canOpenAdmin' => $user->canAccessAppPanel(Panel::Admin),
            'recent' => $user->notifications()->latest()->limit(3)->get(),
        ])->title(__('Home'));
    }
}
