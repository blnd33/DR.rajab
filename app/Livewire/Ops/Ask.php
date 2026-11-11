<?php

namespace App\Livewire\Ops;

use App\Livewire\Concerns\ChatsQuickAnswers;
use App\Models\User;
use App\Services\Insight\QuickAnswers;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * "Ask" — Quick answers on the phone: ready-made questions, or type / scan any code (no AI).
 */
#[Layout('layouts::ops')]
class Ask extends Component
{
    use ChatsQuickAnswers;

    public function mount(): void
    {
        /** @var User $user */
        $user = Auth::user();
        abort_unless(QuickAnswers::canUse($user), 403);
    }

    protected function onPhone(): bool
    {
        return true;
    }

    public function render(): View
    {
        return view('livewire.ops.ask', $this->chatViewData())->title(__('Quick answers'));
    }
}
