<?php

namespace App\Livewire\Ops\Movement;

use App\Enums\ScanContext;
use App\Enums\StockStatus;
use App\Livewire\Ops\Concerns\RunsStockActions;
use App\Models\Customer;
use App\Models\Product;
use App\Services\Barcode\ScanResolver;
use App\Services\Barcode\ScanResultKind;
use App\Services\Movement\ReturnService;
use App\Support\CurrentBranch;
use App\Support\Locale\DigitConverter;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Items coming back from a hospital / customer (Module 14): scan the serial (its origin is
 * shown), or pick a lot product with lot + quantity; choose reason and condition.
 */
#[Layout('layouts::ops')]
class ReturnCreate extends Component
{
    use RunsStockActions;

    /** @var array<string, array<string, mixed>> */
    public array $items = [];

    public ?int $customerId = null;

    public string $notes = '';

    public string $search = '';

    public function mount(): void
    {
        abort_unless($this->user()->canInBranch('returns.customer', CurrentBranch::id()), 403);
    }

    public function handleScan(string $raw, string $source = 'manual'): void
    {
        $raw = trim((string) DigitConverter::toWestern($raw));
        $result = app(ScanResolver::class)->resolve($raw, ScanContext::Lookup, $this->user(), CurrentBranch::id());

        if ($result->kind === ScanResultKind::Unit && $result->unit !== null) {
            $unit = $result->unit;

            if (in_array($unit->status, [StockStatus::InStock, StockStatus::InTransit, StockStatus::InKit], true)) {
                $this->dispatch('scan-feedback', type: 'error');
                $this->dispatch('toast', type: 'error', message: __('Unit :serial is already in our stock (:status).', ['serial' => $unit->serial, 'status' => $unit->status->getLabel()]));

                return;
            }

            $this->items['u'.$unit->id] = [
                'stock_unit_id' => $unit->id,
                'label' => $unit->product->name,
                'code' => $unit->serial,
                'origin' => $unit->status->getLabel().' · '.$unit->branch->code,
                'reason' => 'unused',
                'condition' => 'good',
            ];
            $this->dispatch('scan-feedback', type: 'success');

            return;
        }

        if ($result->product !== null && ! $result->product->isSerialTracked()) {
            $this->addLot($result->product->id, $result->parsed->lot, $result->parsed->expiryDate);
            $this->dispatch('scan-feedback', type: 'success');

            return;
        }

        $this->dispatch('scan-feedback', type: 'error');
        $this->dispatch('toast', type: 'error', message: __('Code not recognised.'));
    }

    public function addLot(int $productId, ?string $lot = null, ?string $expiry = null): void
    {
        $product = Product::query()->findOrFail($productId);

        $this->items['l'.uniqid()] = [
            'product_id' => $product->id,
            'label' => $product->name,
            'code' => $product->sku,
            'lot' => $lot,
            'expiry_date' => $expiry,
            'quantity' => 1,
            'reason' => 'unused',
            'condition' => 'good',
        ];
        $this->search = '';
    }

    public function remove(string $key): void
    {
        unset($this->items[$key]);
    }

    public function submit(ReturnService $returns): void
    {
        $branch = CurrentBranch::get();

        if ($branch === null) {
            return;
        }

        $done = $this->attempt(fn () => $returns->receiveFromCustomer(
            $branch,
            array_values(array_map(fn ($i) => array_intersect_key($i, array_flip(['stock_unit_id', 'product_id', 'lot', 'expiry_date', 'quantity', 'reason', 'condition'])), $this->items)),
            $this->user(),
            $this->customerId ? Customer::query()->find($this->customerId) : null,
            $this->notes ?: null,
        ), __('Return recorded.'));

        if ($done !== null) {
            $this->redirectRoute('ops.tasks', navigate: true);
        }
    }

    public function render(): View
    {
        return view('livewire.ops.movement.return-create', [
            'customers' => Customer::options(),
            'matches' => mb_strlen(trim($this->search)) >= 2 ? Product::query()->active()->where('tracking_type', 'lot')->search($this->search)->limit(8)->get() : collect(),
        ])->title(__('Customer return'));
    }
}
