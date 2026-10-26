<?php

namespace App\Livewire\Ops\Cash;

use App\Enums\LocationType;
use App\Enums\PaymentTerms;
use App\Enums\ScanContext;
use App\Livewire\Ops\Concerns\RunsStockActions;
use App\Models\CashBox;
use App\Models\Customer;
use App\Models\Product;
use App\Models\StockBatch;
use App\Services\Barcode\ScanResolver;
use App\Services\Barcode\ScanResultKind;
use App\Services\Sales\SalesService;
use App\Support\CurrentBranch;
use App\Support\Locale\DigitConverter;
use App\Support\Locale\Display;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Cash sale at the counter (Module 9, P4-12): scan the items, check the prices, take the
 * money into the branch box of the chosen currency.
 */
#[Layout('layouts::ops')]
class SaleCreate extends Component
{
    use RunsStockActions;

    /** @var array<string, array{unit_id: ?int, batch_id: ?int, product_id: int, quantity: int, label: string, code: string, max: int, price: string}> */
    public array $items = [];

    public string $currency = 'USD';

    public ?int $customerId = null;

    public string $walkIn = '';

    public string $discount = '';

    public function mount(): void
    {
        abort_unless($this->user()->canInBranch('sales.create', CurrentBranch::id()), 403);
        $currencies = $this->boxes()->keys();
        $this->currency = $currencies->contains('USD') ? 'USD' : ($currencies->first() ?? 'USD');
    }

    /**
     * Cash boxes of this branch by currency.
     *
     * @return Collection<string, CashBox>
     */
    private function boxes()
    {
        return CashBox::query()->where('branch_id', CurrentBranch::id())->where('is_active', true)->where('type', 'cash')->orderBy('currency')->get()->keyBy('currency');
    }

    public function updatedCurrency(): void
    {
        foreach ($this->items as $key => $item) {
            $this->items[$key]['price'] = $this->listPrice($item['product_id']);
        }
    }

    public function handleScan(string $raw, string $source = 'manual'): void
    {
        $raw = trim((string) DigitConverter::toWestern($raw));
        $branchId = (int) CurrentBranch::id();
        $result = app(ScanResolver::class)->resolve($raw, ScanContext::Lookup, $this->user(), $branchId);

        if ($result->kind === ScanResultKind::Unit && $result->unit !== null) {
            $unit = $result->unit;

            if ($unit->branch_id !== $branchId || ! $unit->status->isOnHand() || $unit->location_type !== LocationType::Shelf) {
                $this->scanFailed(__('Unit :serial is not on a shelf in this branch.', ['serial' => $unit->serial]));

                return;
            }

            $this->items['u'.$unit->id] = ['unit_id' => $unit->id, 'batch_id' => null, 'product_id' => $unit->product_id, 'quantity' => 1, 'label' => $unit->product->name, 'code' => $unit->serial, 'max' => 1, 'price' => $this->listPrice($unit->product_id)];
            $this->dispatch('scan-feedback', type: 'success');

            return;
        }

        if ($result->product !== null && ! $result->product->isSerialTracked()) {
            /** @var StockBatch|null $batch */
            $batch = $result->batches->where('branch_id', $branchId)->filter(fn (StockBatch $b) => $b->location_type === LocationType::Shelf && $b->quantity > 0)->sortBy('expiry_date')->first();

            if ($batch === null) {
                $this->scanFailed(__('No :sku on the shelf in this branch.', ['sku' => $result->product->sku]));

                return;
            }

            $key = 'b'.$batch->id;
            $current = $this->items[$key]['quantity'] ?? 0;
            $this->items[$key] = ['unit_id' => null, 'batch_id' => $batch->id, 'product_id' => $batch->product_id, 'quantity' => min($batch->quantity, $current + 1), 'label' => $result->product->name, 'code' => $batch->lot ?? '—', 'max' => $batch->quantity, 'price' => $this->items[$key]['price'] ?? $this->listPrice($batch->product_id)];
            $this->dispatch('scan-feedback', type: 'success');

            return;
        }

        $this->scanFailed(__('Code not recognised.'));
    }

    public function change(string $key, int $by): void
    {
        if (isset($this->items[$key]) && $this->items[$key]['batch_id'] !== null) {
            $this->items[$key]['quantity'] = max(1, min($this->items[$key]['max'], $this->items[$key]['quantity'] + $by));
        }
    }

    public function remove(string $key): void
    {
        unset($this->items[$key]);
    }

    public function total(): ?string
    {
        try {
            $sum = BigDecimal::zero();

            foreach ($this->items as $item) {
                if ($item['price'] === '') {
                    return null;
                }

                $sum = $sum->plus(BigDecimal::of($this->western($item['price']))->multipliedBy($item['quantity']));
            }

            return (string) $sum->minus($this->discount === '' ? '0' : $this->western($this->discount));
        } catch (MathException) {
            return null;
        }
    }

    public function submit(SalesService $sales): void
    {
        $branch = CurrentBranch::get();
        $box = $this->boxes()->get($this->currency);

        if ($branch === null || $box === null) {
            $this->dispatch('toast', type: 'error', message: __('There is no :currency cash box in this branch.', ['currency' => $this->currency]));

            return;
        }

        $invoice = $this->attempt(fn () => $sales->sell($branch, [
            'currency' => $this->currency,
            'payment_terms' => PaymentTerms::Cash->value,
            'customer_id' => $this->customerId,
            'walk_in_name' => $this->walkIn ?: null,
            'cash_box_id' => $box->id,
            'discount' => $this->discount === '' ? '0' : $this->western($this->discount),
            'items' => array_map(fn ($i) => [
                'stock_unit_id' => $i['unit_id'],
                'stock_batch_id' => $i['batch_id'],
                'quantity' => $i['quantity'],
                'unit_price' => $i['price'] === '' ? null : $this->western($i['price']),
            ], array_values($this->items)),
        ], $this->user()));

        if ($invoice !== null) {
            $this->dispatch('toast', type: 'success', message: __('Sale :number done — :amount into :box.', ['number' => $invoice->number, 'amount' => Display::money($invoice->total, $invoice->currency), 'box' => $box->name]));
            $this->redirectRoute('ops.cash', navigate: true);
        }
    }

    public function render(): View
    {
        return view('livewire.ops.cash.sale-create', [
            'currencies' => $this->boxes()->keys()->all(),
            'customers' => Customer::options(),
            'total' => $this->total(),
        ])->title(__('New sale'));
    }

    private function listPrice(int $productId): string
    {
        $product = Product::query()->find($productId);

        return $product === null ? '' : (app(SalesService::class)->priceFor($product, $this->currency) ?? '');
    }

    private function western(string $value): string
    {
        return str_replace(',', '', trim((string) DigitConverter::toWestern($value)));
    }

    private function scanFailed(string $message): void
    {
        $this->dispatch('scan-feedback', type: 'error');
        $this->dispatch('toast', type: 'error', message: $message);
    }
}
