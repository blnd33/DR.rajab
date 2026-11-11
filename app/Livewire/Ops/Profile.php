<?php

namespace App\Livewire\Ops;

use App\Models\User;
use App\Services\Auth\SessionRegistry;
use App\Services\Insight\MorningSummaryService;
use App\Services\Insight\SummarySettings;
use App\Support\Locale\LocaleRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * "Me": language, my devices (log out a lost phone), push notifications, logout.
 */
#[Layout('layouts::ops')]
class Profile extends Component
{
    /** @var array{enabled: bool, time: string, sections: list<string>, branch_id: int|string|null, skip_fridays: bool} */
    public array $summary = ['enabled' => true, 'time' => '07:30', 'sections' => [], 'branch_id' => null, 'skip_fridays' => false];

    public function mount(): void
    {
        if (MorningSummaryService::canReceive($this->user())) {
            $this->summary = SummarySettings::for($this->user())->toArray();
        }
    }

    public function saveSummary(): void
    {
        abort_unless(MorningSummaryService::canReceive($this->user()), 403);

        try {
            $this->summary = SummarySettings::save($this->user(), $this->summary)->toArray();
        } catch (ValidationException $e) {
            $this->setErrorBag(collect($e->errors())->mapWithKeys(fn ($messages, $field) => ["summary.{$field}" => $messages])->all());

            return;
        }

        $this->resetErrorBag();
        $this->dispatch('toast', type: 'success', message: __('Morning summary settings saved.'));
    }

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
            'canSummary' => MorningSummaryService::canReceive($this->user()),
            'summarySections' => SummarySettings::sectionOptions(),
            'summaryBranches' => SummarySettings::branchOptions($this->user()),
        ])->title(__('Me'));
    }

    private function user(): User
    {
        /** @var User */
        return Auth::user();
    }
}
