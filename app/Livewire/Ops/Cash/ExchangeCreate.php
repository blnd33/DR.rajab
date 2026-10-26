<?php

namespace App\Livewire\Ops\Cash;

use App\Livewire\Ops\Concerns\RunsStockActions;
use App\Models\CashBox;
use App\Services\Money\CurrencyExchangeService;
use App\Services\Money\FxService;
use App\Support\CurrentBranch;
use App\Support\Locale\DigitConverter;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Exchange own money between two boxes of the branch (Module 20, P4-6) at the deal rate;
 * the gain or loss against today's reference rate is recorded.
 */
#[Layout('layouts::ops')]
class ExchangeCreate extends Component
{
    use RunsStockActions;

    public ?int $fromId = null;

    public ?int $toId = null;

    public string $amount = '';

    public string $rate = '';

    public string $note = '';

    public function mount(): void
    {
        abort_unless($this->user()->canInBranch('currency_exchange.create', CurrentBranch::id()), 403);
        $boxes = $this->boxes();
        $from = $boxes->firstWhere('currency', 'USD') ?? $boxes->first();
        $this->fromId = $from?->id;
        $this->toId = $boxes->first(fn (CashBox $b) => $b->currency !== $from?->currency)?->id;
        $this->rate = $this->referenceRate() ?? '';
    }

    /**
     * @return Collection<int, CashBox>
     */
    private function boxes()
    {
        return CashBox::query()->where('branch_id', CurrentBranch::id())->where('is_active', true)->orderBy('type')->orderBy('currency')->get();
    }

    public function updatedFromId(): void
    {
        $this->rate = $this->referenceRate() ?? $this->rate;
    }

    public function updatedToId(): void
    {
        $this->rate = $this->referenceRate() ?? $this->rate;
    }

    private function referenceRate(): ?string
    {
        $from = $this->boxes()->firstWhere('id', $this->fromId);
        $to = $this->boxes()->firstWhere('id', $this->toId);
        $snapshot = app(FxService::class)->current();

        if ($from === null || $to === null || $snapshot === null || $from->currency === $to->currency) {
            return null;
        }

        // Rates are numeric(18,8): IQD → USD needs all eight decimals; trailing zeros are noise.
        $rate = (string) BigDecimal::of(app(FxService::class)->converter($snapshot)->rate($from->currency, $to->currency))->toScale(8, RoundingMode::HalfUp);

        return rtrim(rtrim($rate, '0'), '.');
    }

    public function received(): ?string
    {
        try {
            $amount = BigDecimal::of($this->western($this->amount));
            $rate = BigDecimal::of($this->western($this->rate));
        } catch (MathException) {
            return null;
        }

        return (string) $amount->multipliedBy($rate)->toScale(2, RoundingMode::HalfUp);
    }

    public function submit(CurrencyExchangeService $exchanges): void
    {
        $from = $this->boxes()->firstWhere('id', $this->fromId);
        $to = $this->boxes()->firstWhere('id', $this->toId);

        if ($from === null || $to === null) {
            return;
        }

        $done = $this->attempt(fn () => $exchanges->exchange($from, $to, $this->western($this->amount), $this->western($this->rate), $this->user(), $this->note ?: null), __('Exchanged.'));

        if ($done !== null) {
            $this->redirectRoute('ops.cash', navigate: true);
        }
    }

    public function render(): View
    {
        $boxes = $this->boxes();

        return view('livewire.ops.cash.exchange-create', [
            'boxes' => $boxes,
            'from' => $boxes->firstWhere('id', $this->fromId),
            'to' => $boxes->firstWhere('id', $this->toId),
            'reference' => $this->referenceRate(),
            'received' => $this->received(),
        ])->title(__('Exchange currency'));
    }

    private function western(string $value): string
    {
        return str_replace(',', '', trim((string) DigitConverter::toWestern($value)));
    }
}
