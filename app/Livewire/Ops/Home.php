<?php

namespace App\Livewire\Ops;

use App\Enums\Panel;
use App\Models\User;
use App\Support\CurrentBranch;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Ops home: greeting, current branch, the big Scan button and quick actions
 * for the user's role. Each phase adds its tiles (surgery, transfers, kits, cash).
 */
#[Layout('layouts::ops')]
class Home extends Component
{
    public function render(): View
    {
        /** @var User $user */
        $user = Auth::user();

        return view('livewire.ops.home', [
            'user' => $user,
            'branch' => CurrentBranch::get(),
            'role' => $user->primaryRole(),
            'canOpenAdmin' => $user->canAccessAppPanel(Panel::Admin),
            'recent' => $user->notifications()->latest()->limit(3)->get(),
        ])->title(__('Home'));
    }
}
