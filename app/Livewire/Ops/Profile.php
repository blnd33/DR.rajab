<?php

namespace App\Livewire\Ops;

use App\Models\User;
use App\Services\Auth\SessionRegistry;
use App\Support\Locale\LocaleRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * "Me": language, my devices (log out a lost phone), push notifications, logout.
 */
#[Layout('layouts::ops')]
class Profile extends Component
{
    public function setLocale(string $locale): void
    {
        if (! app(LocaleRegistry::class)->isSupported($locale)) {
            return;
        }

        $this->user()->update(['locale' => $locale]);
        session()->put('locale', $locale);

        // Full reload: direction, fonts and digits change.
        $this->redirectRoute('ops.profile');
    }

    public function revokeDevice(int $sessionId, SessionRegistry $sessions): void
    {
        $session = $this->user()->deviceSessions()->active()->whereKey($sessionId)->first();

        if ($session === null || $session->id === $sessions->currentId(request())) {
            return;
        }

        $sessions->revoke($session, $this->user());

        $this->dispatch('toast', type: 'success', message: __('Device logged out.'));
    }

    public function render(): View
    {
        $sessions = app(SessionRegistry::class);

        return view('livewire.ops.profile', [
            'user' => $this->user(),
            'locales' => app(LocaleRegistry::class)->all(),
            'devices' => $this->user()->deviceSessions()->active()->latest('last_activity_at')->limit(20)->get(),
            'currentSessionId' => $sessions->currentId(request()),
            'vapidPublicKey' => config('webpush.vapid.public_key'),
        ])->title(__('Me'));
    }

    private function user(): User
    {
        /** @var User */
        return Auth::user();
    }
}
