<?php

namespace App\Notifications;

use App\Enums\Panel;
use App\Models\MorningSummary;
use App\Models\User;
use App\Services\Insight\MorningSummaryBuilder;
use App\Support\Locale\Display;

/**
 * "Your morning summary": one short line of counts and totals (no patient names — it can show
 * on a locked phone); tapping it opens the full summary.
 */
class MorningSummaryReady extends AppNotification
{
    public function __construct(public readonly int $summaryId) {}

    public function eventKey(): string
    {
        return 'summary.morning';
    }

    public function title(): string
    {
        $summary = $this->summary();

        return __('Morning summary :date', ['date' => Display::date($summary?->summary_date)]);
    }

    public function body(): string
    {
        return MorningSummaryBuilder::headline($this->summary()->payload ?? []);
    }

    public function url(): string
    {
        $summary = $this->summary();
        $date = $summary?->summary_date?->toDateString();
        $user = $summary?->user;

        return $user instanceof User && $user->canAccessAppPanel(Panel::Admin)
            ? route('filament.admin.pages.morning-summary', array_filter(['date' => $date]))
            : route('ops.summary', array_filter(['date' => $date]));
    }

    public function icon(): string
    {
        return 'heroicon-o-sun';
    }

    private function summary(): ?MorningSummary
    {
        return MorningSummary::query()->with('user')->find($this->summaryId);
    }
}
