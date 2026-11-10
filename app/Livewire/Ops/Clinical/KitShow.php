<?php

namespace App\Livewire\Ops\Clinical;

use App\Enums\CheckoutItemKind;
use App\Enums\KitStatus;
use App\Enums\StockStatus;
use App\Http\Controllers\LabelPrintController;
use App\Livewire\Ops\Concerns\RunsStockActions;
use App\Models\Customer;
use App\Models\Instrument;
use App\Models\Kit;
use App\Models\KitCheckout;
use App\Models\StockBatch;
use App\Models\StockUnit;
use App\Models\User;
use App\Services\Assets\AssetLookup;
use App\Services\Assets\KitService;
use App\Support\Locale\DigitConverter;
use App\Support\Locale\Display;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One kit on the phone: contents vs template; prepare (scan in), check out, return (scan back).
 */
#[Layout('layouts::ops')]
class KitShow extends Component
{
    use RunsStockActions;

    public Kit $kit;

    #[Url]
    public string $mode = 'overview';

    public ?int $customerId = null;

    public ?int $doctorId = null;

    public ?string $plannedDate = null;

    /** @var list<int> serial units scanned back */
    public array $returnedUnits = [];

    /** @var array<int, string> instrument id → returned | missing | damaged */
    public array $instrumentResults = [];

    /** @var array<int, int> checkout item id → quantity back */
    public array $lotReturned = [];

    /** Print sheet for the instruments "Fill from its list" just registered. */
    public ?string $labelsUrl = null;

    public function mount(Kit $kit): void
    {
        abort_unless($this->user()->can('view', $kit), 403);
        $this->kit = $kit;

        if (! in_array($this->mode, ['overview', 'prepare', 'checkout', 'return'], true)) {
            $this->mode = 'overview';
        }

        $this->prepareReturn();
    }

    public function setMode(string $mode): void
    {
        $this->mode = in_array($mode, ['overview', 'prepare', 'checkout', 'return'], true) ? $mode : 'overview';
        $this->prepareReturn();
    }

    /** Prepare: anything scanned goes into the kit. Return: scanned items are ticked as back. */
    public function handleScan(string $raw, KitService $kits): void
    {
        if ($this->mode === 'return') {
            $this->scanBack($raw);

            return;
        }

        $added = $this->attempt(fn () => $kits->addByScan($this->kit, $raw, $this->user()), scan: true);

        if ($added !== null) {
            $this->dispatch('toast', type: 'success', message: __(':code added to the kit.', ['code' => $added['code']]));
            $this->kit->refresh();
        }
    }

    public function removeUnit(int $unitId, KitService $kits): void
    {
        $unit = StockUnit::query()->where('kit_id', $this->kit->id)->findOrFail($unitId);
        $this->attempt(fn () => $kits->removeUnit($unit, $this->user()), __('Taken out of the kit.'));
    }

    public function removeInstrument(int $instrumentId, KitService $kits): void
    {
        $instrument = Instrument::query()->where('kit_id', $this->kit->id)->findOrFail($instrumentId);
        $this->attempt(fn () => $kits->removeInstrument($instrument, $this->user()), __('Taken out of the kit.'));
    }

    public function removeBatch(int $batchId, KitService $kits): void
    {
        $batch = StockBatch::query()->where('kit_id', $this->kit->id)->findOrFail($batchId);
        $this->attempt(fn () => $kits->removeBatch($batch, $batch->quantity, $this->user()), __('Taken out of the kit.'));
    }

    /** "Fill from its list": every missing instrument of the list into the kit (shelf first, then new). */
    public function fillFromList(KitService $kits): void
    {
        $result = $this->attempt(fn () => $kits->fillFromList($this->kit, $this->user()));

        if ($result === null) {
            return;
        }

        $this->labelsUrl = $result['registered'] !== [] ? LabelPrintController::url('instrument', $result['registered']) : null;
        $this->dispatch('toast', type: 'success', message: trans_choice(':count instrument added to the kit.|:count instruments added to the kit.', $result['added'], ['count' => Display::number($result['added'])]));
        $this->kit->refresh();
    }

    public function markPrepared(KitService $kits): void
    {
        $this->attempt(fn () => $kits->markPrepared($this->kit, $this->user()), __('Kit marked as prepared.'));
    }

    public function checkout(KitService $kits): void
    {
        $this->validate(['customerId' => 'required|integer', 'plannedDate' => 'nullable|date']);

        $done = $this->attempt(fn () => $kits->checkout(
            $this->kit,
            Customer::query()->findOrFail($this->customerId),
            $this->user(),
            $this->doctorId !== null ? User::query()->find($this->doctorId) : null,
            plannedDate: $this->plannedDate,
        ), __('Kit checked out.'));

        if ($done !== null) {
            $this->kit->refresh();
            $this->setMode('overview');
        }
    }

    public function confirmReturn(KitService $kits): void
    {
        $checkout = $this->checkoutRecord();

        if ($checkout === null) {
            return;
        }

        $done = $this->attempt(fn () => $kits->return($checkout, $this->instrumentResults, $this->returnedUnits, array_map('intval', $this->lotReturned), $this->user()), __('Kit returned and reconciled.'));

        if ($done !== null) {
            $this->kit->refresh();
            $this->setMode('overview');
        }
    }

    public function render(): View
    {
        $this->kit->refresh()->load(['type', 'branch', 'customer', 'currentCheckout.customer']);
        $user = $this->user();
        $available = $this->kit->status === KitStatus::Available;
        $checkout = $this->checkoutRecord();

        return view('livewire.ops.clinical.kit-show', [
            'compare' => app(KitService::class)->compare($this->kit),
            'fillPlan' => $this->mode === 'prepare' && $available && $user->canInBranch('kits.prepare', $this->kit->branch_id) ? app(KitService::class)->fillPlan($this->kit) : null,
            'units' => StockUnit::query()->with('product')->where('kit_id', $this->kit->id)->whereIn('status', [StockStatus::InKit, StockStatus::OutAtHospital])->orderBy('expiry_date')->get(),
            'batches' => StockBatch::query()->with('product')->where('kit_id', $this->kit->id)->where('quantity', '>', 0)->orderBy('expiry_date')->get(),
            'instruments' => Instrument::query()->with('type')->where('kit_id', $this->kit->id)->orderBy('code')->get(),
            'checkout' => $checkout,
            'returnItems' => $checkout?->items()->with(['instrument.type', 'stockUnit', 'stockBatch', 'product'])->get() ?? collect(),
            'canPrepare' => $available && $user->canInBranch('kits.prepare', $this->kit->branch_id),
            'canCheckout' => $available && $user->canInBranch('kits.checkout', $this->kit->branch_id),
            'canReturn' => $checkout !== null && $user->canInBranch('kits.return', $this->kit->branch_id),
            'hospitals' => Customer::options(),
            'doctors' => User::doctorOptions(),
        ])->title($this->kit->code);
    }

    private function scanBack(string $raw): void
    {
        $code = trim((string) DigitConverter::toWestern($raw));
        $items = $this->checkoutRecord()?->items()->with(['stockUnit', 'instrument'])->get() ?? collect();

        $unit = $items->first(fn ($i) => $i->item_kind === CheckoutItemKind::Unit && $i->stockUnit?->serial === $code);
        $instrument = $items->first(fn ($i) => $i->item_kind === CheckoutItemKind::Instrument && ($i->instrument?->barcode === $code || $i->instrument?->code === mb_strtoupper($code)));

        if ($unit !== null) {
            $this->returnedUnits = array_values(array_unique([...$this->returnedUnits, (int) $unit->stock_unit_id]));
        } elseif ($instrument !== null) {
            $this->instrumentResults[(int) $instrument->instrument_id] = 'returned';
        } elseif (app(AssetLookup::class)->kit($code)?->id !== $this->kit->id) {
            $this->dispatch('scan-feedback', type: 'error');
            $this->dispatch('toast', type: 'error', message: __(':code did not go out with this kit.', ['code' => $code]));

            return;
        }

        $this->dispatch('scan-feedback', type: 'success');
    }

    private function prepareReturn(): void
    {
        $checkout = $this->checkoutRecord();

        if ($checkout === null) {
            return;
        }

        foreach ($checkout->items()->with(['stockBatch'])->get() as $item) {
            if ($item->item_kind === CheckoutItemKind::Instrument) {
                $this->instrumentResults[(int) $item->instrument_id] ??= 'missing';
            }

            if ($item->item_kind === CheckoutItemKind::Batch) {
                $this->lotReturned[$item->id] ??= (int) ($item->stockBatch->quantity ?? 0);
            }
        }
    }

    private function checkoutRecord(): ?KitCheckout
    {
        return $this->kit->status === KitStatus::OutAtHospital ? $this->kit->currentCheckout : null;
    }
}
