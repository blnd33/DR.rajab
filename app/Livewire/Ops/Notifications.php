<?php

namespace App\Livewire\Ops;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts::ops')]
class Notifications extends Component
{
    use WithPagination;

    public function markAsRead(string $id): void
    {
        $this->user()->notifications()->whereKey($id)->first()?->markAsRead();
    }

    public function markAllAsRead(): void
    {
        $this->user()->unreadNotifications()->update(['read_at' => now()]);

        $this->dispatch('toast', type: 'success', message: __('All notifications marked as read.'));
    }

    public function render(): View
    {
        return view('livewire.ops.notifications', [
            'notifications' => $this->user()->notifications()->latest()->paginate(20),
            'unread' => $this->user()->unreadNotifications()->count(),
        ])->title(__('Notifications'));
    }

    private function user(): User
    {
        /** @var User */
        return Auth::user();
    }
}
