<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Auth\EditProfile;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\User;
use App\Services\Insight\MorningSummaryBuilder;
use App\Services\Insight\MorningSummaryService;
use App\Services\Insight\SummarySettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

/**
 * Insight → Morning summary: today's summary (or any past morning, as it was sent), the same
 * content the phone notification opens.
 */
class MorningSummaryPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSun;

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.morning-summary';

    #[Url]
    public ?string $date = null;

    public static function getSlug(?Panel $panel = null): string
    {
        return 'morning-summary';
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Insight');
    }

    public static function getNavigationLabel(): string
    {
        return __('Daily Business Report');
    }

    public function getTitle(): string
    {
        return __('Daily Business Report');
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && MorningSummaryService::canReceive($user);
    }

    public function mount(): void
    {
        $this->date = $this->validDate($this->date);
    }

    public function updatedDate(): void
    {
        $this->date = $this->validDate($this->date);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('sendNow')
                ->label(__('Send it to me now'))
                ->icon(Heroicon::OutlinedBellAlert)
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(__('Today\'s report is built again and sent to your bell and your phone.'))
                ->action(function () {
                    app(MorningSummaryService::class)->send(ServiceAction::user());
                    $this->date = MorningSummaryService::today();
                    Notification::make()->title(__('Daily Business Report sent.'))->success()->send();
                }),
            Action::make('settings')
                ->label(__('My report settings'))
                ->icon(Heroicon::OutlinedCog6Tooth)
                ->color('gray')
                ->url(EditProfile::getUrl()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = ServiceAction::user();
        $summary = app(MorningSummaryService::class)->forDate($user, (string) $this->date);

        return [
            'summary' => $summary,
            'headline' => MorningSummaryBuilder::headline($summary['payload']),
            'settings' => SummarySettings::for($user),
            'today' => MorningSummaryService::today(),
        ];
    }

    private function validDate(?string $date): string
    {
        $today = MorningSummaryService::today();

        if ($date === null || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $today;
        }

        try {
            $parsed = Carbon::createFromFormat('Y-m-d', $date)->toDateString();
        } catch (\Throwable) {
            return $today;
        }

        return $parsed > $today ? $today : $parsed;
    }
}
