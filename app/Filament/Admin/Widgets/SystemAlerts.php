<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Pages\SystemHealthPage;
use App\Models\User;
use App\Services\Health\AlertDismissals;
use App\Services\Health\SystemHealth;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

/**
 * Dashboard strip with what needs the Super Admin's attention on the system side (P5-5 / P5-6):
 * failed or late backups and daily jobs, disk space, old exchange rates. Hidden when all is well,
 * or when this user dismissed the same problems in the last 24 hours.
 */
class SystemAlerts extends Widget
{
    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.system-alerts';

    public bool $dismissed = false;

    public static function canView(): bool
    {
        $user = Auth::user();

        if (! $user instanceof User || ! $user->can('system.health')) {
            return false;
        }

        $problems = app(SystemHealth::class)->problems();

        return $problems !== [] && ! app(AlertDismissals::class)->isDismissed($user, $problems);
    }

    public function dismiss(): void
    {
        /** @var User $user */
        $user = Auth::user();
        abort_unless($user->can('system.health'), 403);

        app(AlertDismissals::class)->dismiss($user, app(SystemHealth::class)->problems());
        $this->dismissed = true;

        Notification::make()
            ->title(__('Alerts hidden'))
            ->body(__('They come back when a new problem appears, or after 24 hours. System health still shows them.'))
            ->success()
            ->send();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'problems' => $this->dismissed ? [] : app(SystemHealth::class)->problems(),
            'url' => SystemHealthPage::getUrl(),
        ];
    }
}
