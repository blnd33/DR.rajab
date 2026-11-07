<?php

namespace App\Livewire\Ops\Stock;

use App\Enums\ScanContext;
use App\Exceptions\Domain\StockRuleViolation;
use App\Models\Product;
use App\Models\User;
use App\Services\Barcode\ScanResolver;
use App\Services\Barcode\ScanResultKind;
use App\Services\Catalog\ProductService;
use App\Services\Stock\Data\ReceiveLine;
use App\Services\Stock\StockMovementService;
use App\Support\CurrentBranch;
use App\Support\Locale\DigitConverter;
use App\Support\Locale\Display;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Receive stock on the phone (opening stock, P1-1): scan box after box into the current
 * branch, check the list, confirm. Costs come from the product defaults (the admin can
 * correct them later); purchases arrive in Phase 4.
 *
 * A box barcode the system doesn't know yet (a supplier GTIN) is learned on the spot: the
 * user picks the product once and the barcode is saved on it.
 */
#[Layout('layouts::ops')]
class Receive extends Component
{
    /** First code passed from the Scan screen ("Receive this unit"). */
    #[Url]
    public ?string $code = null;

    /** @var array<string, array<string, mixed>> */
    public array $lines = [];

    public string $productSearch = '';

    /** @var array{gtin: string, prefill: array<string, mixed>}|null a scanned box barcode nobody knows yet */
    public ?array $unknown = null;

    public string $linkSearch = '';

    public function mount(): void
    {
        abort_unless($this->user()->canInBranch('stock.receive', CurrentBranch::id()), 403);

        if (filled($this->code)) {
            $this->handleScan($this->code, 'manual');
            $this->code = null;
        }
    }

    public function handleScan(string $raw, string $source = 'manual'): void
    {
        $raw = DigitConverter::toWestern(trim($raw)) ?? '';

        if ($raw === '') {
            return;
        }

        $result = app(ScanResolver::class)->resolve(mb_substr($raw, 0, 500), ScanContext::Receive, $this->user(), CurrentBranch::id());

        foreach ($result->warnings as $warning) {
            $this->dispatch('toast', type: 'warning', message: $warning);
        }

        if ($result->kind === ScanResultKind::Unit) {
            $this->dispatch('scan-feedback', type: 'error');
            $this->dispatch('toast', type: 'error', message: __('Serial :serial is already in the system (:status, :branch).', [
                'serial' => $result->unit->serial,
                'status' => $result->unit->status->getLabel(),
                'branch' => $result->unit->branch->code,
            ]));

            return;
        }

        if ($result->product === null) {
            $this->dispatch('scan-feedback', type: 'error');

            if ($result->parsed->gtin !== null && $this->canLinkBarcodes()) {
                $this->unknown = ['gtin' => $result->parsed->gtin, 'prefill' => $result->prefill()];
                $this->linkSearch = '';

                return;
            }

            $this->dispatch('toast', type: 'error', message: $result->parsed->gtin !== null
                ? __('New box barcode. Ask an admin to link it to its product, or search the product below.')
                : __('Code not recognised. Search the product below.'));

            return;
        }

        $this->addLine($result->product, $result->prefill());
        $this->dispatch('scan-feedback', type: 'success');
    }

    /**
     * The user says which product the unknown box is: remember its barcode, add the box.
     */
    public function linkProduct(int $productId, ProductService $products): void
    {
        abort_unless($this->canLinkBarcodes(), 403);

        $product = Product::query()->active()->find($productId);

        if ($this->unknown === null || $product === null) {
            return;
        }

        try {
            $products->learnBarcode($product, $this->unknown['gtin'], $this->user());
        } catch (ValidationException $e) {
            $this->dispatch('toast', type: 'error', message: collect($e->errors())->flatten()->first());

            return;
        }

        $this->addLine($product, $this->unknown['prefill']);
        $this->unknown = null;
        $this->linkSearch = '';
        $this->dispatch('scan-feedback', type: 'success');
        $this->dispatch('toast', type: 'success', message: __('Barcode saved on :sku — next time it scans by itself.', ['sku' => $product->sku]));
    }

    public function cancelLink(): void
    {
        $this->unknown = null;
        $this->linkSearch = '';
    }

    public function addProduct(int $productId): void
    {
        $product = Product::query()->active()->find($productId);

        if ($product !== null) {
            $this->addLine($product, ['serial' => null, 'lot' => null, 'expiry_date' => null, 'production_date' => null]);
        }

        $this->productSearch = '';
    }

    public function increment(string $key, int $by = 1): void
    {
        if (isset($this->lines[$key]) && ! $this->lines[$key]['serial_tracked'] && blank($this->lines[$key]['serial'])) {
            $this->lines[$key]['quantity'] = max(1, (int) $this->lines[$key]['quantity'] + $by);
        }
    }

    public function removeLine(string $key): void
    {
        unset($this->lines[$key]);
    }

    public function save(StockMovementService $stock): void
    {
        $branch = CurrentBranch::get();

        if ($branch === null || $this->lines === []) {
            return;
        }

        $lines = array_map(fn (array $line) => new ReceiveLine(
            productId: (int) $line['product_id'],
            // A box with its own SN is one piece.
            quantity: $line['serial_tracked'] || filled($line['serial']) ? 1 : max(1, (int) $line['quantity']),
            serial: filled($line['serial']) ? DigitConverter::toWestern(trim((string) $line['serial'])) : null,
            lot: filled($line['lot']) ? DigitConverter::toWestern(trim((string) $line['lot'])) : null,
            expiryDate: filled($line['expiry_date']) ? (string) $line['expiry_date'] : null,
            productionDate: filled($line['production_date']) ? (string) $line['production_date'] : null,
            unitCost: (string) ($line['unit_cost'] ?? '0'),
            costCurrency: (string) $line['cost_currency'],
        ), array_values($this->lines));

        try {
            $result = $stock->receive($branch, $lines, $this->user(), reason: __('Received on the phone'));
        } catch (StockRuleViolation $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        }

        foreach ($result->warnings as $warning) {
            $this->dispatch('toast', type: 'warning', message: $warning, timeout: 8000);
        }

        $this->lines = [];
        $this->dispatch('toast', type: 'success', message: trans_choice(':count item added to :branch.|:count items added to :branch.', $result->quantity, [
            'count' => Display::number($result->quantity),
            'branch' => $branch->code,
        ]));
    }

    public function render(): View
    {
        $matches = mb_strlen(trim($this->productSearch)) >= 2
            ? Product::query()->active()->search($this->productSearch)->orderByUsage()->orderBy('sku')->limit(8)->get()
            : collect();

        $linkMatches = $this->unknown !== null && mb_strlen(trim($this->linkSearch)) >= 2
            ? Product::query()->active()->search($this->linkSearch)->orderByUsage()->orderBy('sku')->limit(8)->get()
            : collect();

        return view('livewire.ops.stock.receive', [
            'branch' => CurrentBranch::get(),
            'matches' => $matches,
            'linkMatches' => $linkMatches,
            'total' => array_sum(array_map(fn ($l) => (int) $l['quantity'], $this->lines)),
        ])->title(__('Receive stock'));
    }

    /**
     * @param  array<string, mixed>  $prefill
     */
    private function addLine(Product $product, array $prefill): void
    {
        // A box with its own SN gets its own line; boxes with only a LOT add up.
        if (! $product->isSerialTracked() && blank($prefill['serial'])) {
            foreach ($this->lines as $key => $line) {
                if ($line['product_id'] === $product->id && blank($line['serial']) && $line['lot'] === $prefill['lot'] && $line['expiry_date'] === $prefill['expiry_date']) {
                    $this->lines[$key]['quantity']++;

                    return;
                }
            }
        } elseif (filled($prefill['serial'])) {
            foreach ($this->lines as $line) {
                if ($line['serial'] === $prefill['serial']) {
                    $this->dispatch('toast', type: 'warning', message: __('Serial :serial is already in the list.', ['serial' => $prefill['serial']]));

                    return;
                }
            }
        }

        $this->lines = [(string) Str::uuid() => [
            'product_id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'size' => $product->size,
            'serial_tracked' => $product->isSerialTracked(),
            'requires_expiry' => $product->requires_expiry,
            'serial' => $prefill['serial'],
            'lot' => $prefill['lot'],
            'expiry_date' => $prefill['expiry_date'],
            'production_date' => $prefill['production_date'],
            'quantity' => 1,
            'unit_cost' => $product->purchase_price ?? '0',
            'cost_currency' => $product->purchase_currency ?? 'USD',
        ]] + $this->lines;
    }

    private function canLinkBarcodes(): bool
    {
        return $this->user()->can('products.link_barcodes');
    }

    private function user(): User
    {
        /** @var User */
        return Auth::user();
    }
}
