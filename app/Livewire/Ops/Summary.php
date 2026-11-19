<?php

namespace App\Livewire\Ops;

use App\Models\User;
use App\Services\Insight\MorningSummaryBuilder;
use App\Services\Insight\MorningSummaryService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Phone: the morning summary (opened from the notification or the Home card), any past
 * morning as it was sent.
 */
#[Layout('layouts::ops')]
class Summary extends Component
{
    #[Url]
    public ?string $date = null;

    public function mount(): void
    {
        abort_unless(MorningSummaryService::canReceive($this->user()), 403);
        $this->date = $this->validDate($this->date);
    }

    public function updatedDate(): void
    {
        $this->date = $this->validDate($this->date);
    }

    public function render(): View
    {
        $summary = app(MorningSummaryService::class)->forDate($this->user(), (string) $this->date);

        return view('livewire.ops.summary', [
            'summary' => $summary,
            'headline' => MorningSummaryBuilder::headline($summary['payload']),
            'today' => MorningSummaryService::today(),
        ])->title(__('Daily Business Report'));
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

    private function user(): User
    {
        /** @var User */
        return Auth::user();
    }
}
