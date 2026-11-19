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
 * money into the branch box of the chosen currency. Priced per item, or one total split by
 * each piece's share of the cost (the shares can be changed).
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

    /** Ticked: the money is taken now; otherwise the invoice starts unpaid. */
    public bool $paidNow = false;

    /** per_item | split */
    public string $pricing = SalesService::PER_ITEM;

    /** The one price for all the pieces (split pricing). */
    public string $saleTotal = '';

    /** @var array<string, string> item key → percentage typed by hand (empty = cost share) */
    public array $shares = [];

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
                $this->scanFailed(__('Unit :serial is not on a shelf in this station.', ['serial' => $unit->serial]));

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
                $this->scanFailed(__('No :sku on the shelf in this station.', ['sku' => $result->product->sku]));

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
        unset($this->items[$key], $this->shares[$key]);
    }

    public function updatedPricing(): void
    {
        $this->pricing = $this->pricing === SalesService::SPLIT ? SalesService::SPLIT : SalesService::PER_ITEM;
    }

    /**
     * Back to the shares from the cost.
     */
    public function resetShares(): void
    {
        $this->shares = [];
    }

    /**
     * Split pricing: each piece with its cost share, chosen share and amount.
     *
     * @return array<string, array{cost: string, cost_share: string, share: string, amount: ?string}>
     */
    public function splitPreview(): array
    {
        $branch = CurrentBranch::get();

        if ($this->pricing !== SalesService::SPLIT || $this->items === [] || $branch === null) {
            return [];
        }

        $keys = array_keys($this->items);

        try {
            $rows = app(SalesService::class)->quoteSplit($branch, $this->saleLines(), $this->currency, $this->saleTotal === '' ? null : $this->western($this->saleTotal));
        } catch (\Throwable) {
            return [];
        }

        $preview = [];

        foreach ($rows as $i => $row) {
            if (isset($keys[$i])) {
                $preview[$keys[$i]] = ['cost' => $row['cost'], 'cost_share' => $row['cost_share'], 'share' => $row['share'], 'amount' => $row['amount']];
            }
        }

        return $preview;
    }

    public function total(): ?string
    {
        if ($this->pricing === SalesService::SPLIT) {
            try {
                return $this->saleTotal === '' ? null : (string) BigDecimal::of($this->western($this->saleTotal));
            } catch (MathException) {
                return null;
            }
        }

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
        $box = $this->paidNow ? $this->boxes()->get($this->currency) : null;

        if ($branch === null || ($this->paidNow && $box === null)) {
            $this->dispatch('toast', type: 'error', message: __('There is no :currency cash box in this station.', ['currency' => $this->currency]));

            return;
        }

        $invoice = $this->attempt(fn () => $sales->sell($branch, [
            'currency' => $this->currency,
            'payment_terms' => $this->paidNow ? PaymentTerms::Cash->value : PaymentTerms::Later->value,
            'customer_id' => $this->customerId,
            'walk_in_name' => $this->walkIn ?: null,
            'cash_box_id' => $box?->id,
            'pricing' => $this->pricing,
            'sale_total' => $this->pricing === SalesService::SPLIT ? $this->western($this->saleTotal) : null,
            'discount' => $this->pricing === SalesService::SPLIT || $this->discount === '' ? '0' : $this->western($this->discount),
            'items' => $this->saleLines(),
        ], $this->user()));

        if ($invoice !== null) {
            $this->dispatch('toast', type: 'success', message: $box !== null
                ? __('Sale :number done — :amount into :box.', ['number' => $invoice->number, 'amount' => Display::money($invoice->total, $invoice->currency), 'box' => $box->name])
                : __('Sale :number saved — :amount not paid yet.', ['number' => $invoice->number, 'amount' => Display::money($invoice->total, $invoice->currency)]));
            $this->redirectRoute('ops.cash', navigate: true);
        }
    }

    public function render(): View
    {
        return view('livewire.ops.cash.sale-create', [
            'currencies' => $this->boxes()->keys()->all(),
            'customers' => Customer::options(),
            'total' => $this->total(),
            'split' => $this->splitPreview(),
            'canSeeCost' => $this->user()->canInBranch('stock.view_cost', CurrentBranch::id()),
        ])->title(__('New sale'));
    }

    /**
     * The lines as SalesService reads them.
     *
     * @return list<array<string, mixed>>
     */
    private function saleLines(): array
    {
        $typed = array_filter($this->shares, fn ($s) => trim((string) $s) !== '');

        return array_values(array_map(fn (string $key, array $i) => [
            'stock_unit_id' => $i['unit_id'],
            'stock_batch_id' => $i['batch_id'],
            'quantity' => $i['quantity'],
            'unit_price' => $this->pricing === SalesService::SPLIT || $i['price'] === '' ? null : $this->western($i['price']),
            // Hand-typed shares only when some were typed (the service then wants all of them).
            'share' => $this->pricing === SalesService::SPLIT && $typed !== [] ? $this->western((string) ($this->shares[$key] ?? '')) : null,
        ], array_keys($this->items), $this->items));
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
