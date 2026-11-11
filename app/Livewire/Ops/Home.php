<?php

namespace App\Livewire\Ops;

use App\Enums\Panel;
use App\Enums\SurgeryStatus;
use App\Models\Kit;
use App\Models\LowStock;
use App\Models\MorningSummary;
use App\Models\Surgery;
use App\Models\User;
use App\Services\Clinical\SurgeryService;
use App\Services\Insight\MorningSummaryBuilder;
use App\Services\Insight\MorningSummaryService;
use App\Services\Insight\SummarySettings;
use App\Services\Stock\StockQuery;
use App\Support\CurrentBranch;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Ops home. Whoever can log surgeries (the doctors) gets a short clinical home: one big
 * New surgery button and a few tiles. Everyone else gets the stock home: the big Scan button,
 * stock alerts for the branch (expired / next expiry level, below minimum) and quick actions.
 */
#[Layout('layouts::ops')]
class Home extends Component
{
    public function render(): View
    {
        /** @var User $user */
        $user = Auth::user();

        $branchId = CurrentBranch::id();
        $canStock = $branchId !== null && $user->canInBranch('stock.view', $branchId);
        $clinical = $user->can('create', Surgery::class);

        $canSummary = MorningSummaryService::canReceive($user);
        $summary = $canSummary ? MorningSummary::query()->where('user_id', $user->id)
            ->whereDate('summary_date', MorningSummaryService::today())->whereNotNull('sent_at')->first() : null;

        return view('livewire.ops.home', [
            'user' => $user,
            'canSummary' => $canSummary,
            'summaryLine' => $summary !== null ? MorningSummaryBuilder::headline($summary->payload) : null,
            'summaryTime' => $canSummary ? SummarySettings::for($user)->time : null,
            'branch' => CurrentBranch::get(),
            'clinical' => $clinical,
            'drafts' => $clinical ? app(SurgeryService::class)->visibleTo($user)->where('status', SurgeryStatus::Draft)->where('created_by', $user->id)->count() : 0,
            'canKits' => $user->can('viewAny', Kit::class),
            'expiry' => $canStock && ! $clinical ? collect(app(StockQuery::class)->expiryCounts([$branchId]))->take(2)->values() : collect(),
            'lowStock' => $canStock && ! $clinical ? LowStock::query()->where('branch_id', $branchId)->count() : null,
            'canReceive' => $branchId !== null && $user->canInBranch('stock.receive', $branchId),
            'canRecall' => $user->can('stock.recall'),
            'canStock' => $canStock,
            'canOpenAdmin' => $user->canAccessAppPanel(Panel::Admin),
            'recent' => $clinical ? collect() : $user->notifications()->latest()->limit(3)->get(),
        ])->title(__('Home'));
    }
}
