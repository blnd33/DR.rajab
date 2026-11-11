<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Pages\MorningSummaryPage;
use App\Models\MorningSummary;
use App\Models\User;
use App\Services\Insight\MorningSummaryBuilder;
use App\Services\Insight\MorningSummaryService;
use App\Services\Insight\SummarySettings;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

/**
 * Dashboard "Today" card: this morning's summary line, or when it will arrive.
 * (It shows what was sent — the dashboard does not rebuild the summary on every visit.)
 */
class MorningSummaryCard extends Widget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.morning-summary-card';

    public static function canView(): bool
    {
        $user = Auth::user();

        return $user instanceof User && MorningSummaryService::canReceive($user);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        /** @var User $user */
        $user = Auth::user();
        $summary = MorningSummary::query()->where('user_id', $user->id)
            ->whereDate('summary_date', MorningSummaryService::today())->whereNotNull('sent_at')->first();

        return [
            'headline' => $summary !== null ? MorningSummaryBuilder::headline($summary->payload) : null,
            'settings' => SummarySettings::for($user),
            'url' => MorningSummaryPage::getUrl(),
        ];
    }
}
