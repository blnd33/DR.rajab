<?php

namespace App\Livewire\Ops\Cash;

use App\Enums\ClosingStatus;
use App\Livewire\Ops\Concerns\RunsStockActions;
use App\Models\CashBox;
use App\Models\CashBoxClosing;
use App\Services\Money\CashClosingService;
use App\Support\Locale\DigitConverter;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Daily closing of one cash box (Module 19, P4-5): count the drawer, see the difference
 * live, explain it, submit. Submitted closings are locked.
 */
#[Layout('layouts::ops')]
class CloseDay extends Component
{
    use RunsStockActions;

    public CashBox $box;

    public string $counted = '';

    public string $reason = '';

    public function mount(CashBox $box): void
    {
        abort_unless($box->is_active && $box->isCash() && $this->user()->canInBranch('cash_boxes.close_day', $box->branch_id), 403);
        $this->box = $box;
    }

    public function difference(): ?string
    {
        $counted = trim((string) DigitConverter::toWestern($this->counted));

        if ($counted === '') {
            return null;
        }

        try {
            return (string) BigDecimal::of(str_replace(',', '', $counted))->minus($this->box->fresh()->current_balance ?? '0');
        } catch (MathException) {
            return null;
        }
    }

    public function submit(CashClosingService $closings): void
    {
        $counted = str_replace(',', '', trim((string) DigitConverter::toWestern($this->counted)));

        $closing = $this->attempt(function () use ($closings, $counted) {
            $draft = $closings->start($this->box, $this->user());

            return $closings->submit($draft, $counted === '' ? '0' : $counted, $this->user(), $this->reason ?: null);
        }, __('Closing submitted and locked.'));

        if ($closing !== null) {
            $this->redirectRoute('ops.cash', navigate: true);
        }
    }

    public function render(): View
    {
        $today = now(config('orthotrack.timezone'))->toDateString();
        $done = CashBoxClosing::query()->where('cash_box_id', $this->box->id)->where('business_date', $today)->where('status', '<>', ClosingStatus::Draft)->first();

        return view('livewire.ops.cash.close-day', [
            'expected' => $this->box->fresh()->current_balance ?? '0',
            'difference' => $this->difference(),
            'done' => $done,
            'today' => $today,
        ])->title(__('Close the day'));
    }
}
