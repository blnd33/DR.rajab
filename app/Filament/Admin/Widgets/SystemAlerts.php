<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Pages\SystemHealthPage;
use App\Models\User;
use App\Services\Health\SystemHealth;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

/**
 * Dashboard strip with what needs the Super Admin's attention on the system side (P5-5 / P5-6):
 * failed or late backups and daily jobs, disk space, old exchange rates. Hidden when all is well.
 */
class SystemAlerts extends Widget
{
    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.system-alerts';

    public static function canView(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('system.health') && app(SystemHealth::class)->problems() !== [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'problems' => app(SystemHealth::class)->problems(),
            'url' => SystemHealthPage::getUrl(),
        ];
    }
}
