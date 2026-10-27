<?php

namespace App\Filament\Admin\Pages;

use App\Models\User;
use App\Services\Health\SystemHealth;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;

/**
 * System health (P5-6, Module 28): backups, scheduled jobs, queue, disk, exchange rates,
 * versions. For the Super Admin and Coda support.
 */
class SystemHealthPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'system-health';

    protected string $view = 'filament.pages.system-health';

    public static function getNavigationGroup(): ?string
    {
        return __('System');
    }

    public static function getNavigationLabel(): string
    {
        return __('System health');
    }

    public function getTitle(): string
    {
        return __('System health');
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('system.health');
    }

    public static function getNavigationBadge(): ?string
    {
        $problems = count(app(SystemHealth::class)->problems());

        return $problems > 0 ? (string) $problems : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $health = app(SystemHealth::class);
        $health->problems(fresh: true);

        return [
            'groups' => collect($health->checks())->groupBy('group'),
            'versions' => $health->versions(),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backup')
                ->label(__('Run a backup now'))
                ->icon(Heroicon::OutlinedCloudArrowUp)
                ->requiresConfirmation()
                ->modalDescription(__('Database and uploads are copied to the backup storage. It can take a few minutes.'))
                ->action(function () {
                    $code = Artisan::call('backup:run', ['--only-db' => false]);
                    $code === 0
                        ? Notification::make()->title(__('Backup finished.'))->success()->send()
                        : Notification::make()->title(__('Backup failed — see the log.'))->body(mb_substr(Artisan::output(), -500))->danger()->persistent()->send();
                }),
        ];
    }
}
