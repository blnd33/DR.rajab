<?php

namespace App\Livewire\Ops\Stock;

use App\Enums\StockStatus;
use App\Models\ExpiryLevel;
use App\Models\LowStock;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockOnHand;
use App\Models\StockUnit;
use App\Models\User;
use App\Services\Expiry\ExpiryClassifier;
use App\Services\Stock\StockQuery;
use App\Support\CurrentBranch;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Stock of the current branch as cards: products with quantities (search / scan), or the
 * items in one expiry level, or the products below minimum.
 */
#[Layout('layouts::ops')]
class Index extends Component
{
    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public ?int $level = null;

    #[Url]
    public bool $low = false;

    public int $perPage = 20;

    public function mount(): void
    {
        abort_unless($this->user()->canInBranch('stock.view', CurrentBranch::id()), 403);
    }

    public function updatedSearch(): void
    {
        $this->perPage = 20;
    }

    public function setLevel(?int $level): void
    {
        $this->level = $level === $this->level ? null : $level;
        $this->low = false;
        $this->perPage = 20;
    }

    public function toggleLow(): void
    {
        $this->low = ! $this->low;
        $this->level = null;
        $this->perPage = 20;
    }

    public function loadMore(): void
    {
        $this->perPage += 20;
    }

    public function render(): View
    {
        $branchId = (int) CurrentBranch::id();
        $levels = app(ExpiryClassifier::class)->levels();
        $counts = collect(app(StockQuery::class)->expiryCounts([$branchId]))->mapWithKeys(fn ($c) => [$c['level']->id => $c['total']]);
        $lowCount = LowStock::query()->where('branch_id', $branchId)->count();

        $level = $this->level !== null ? $levels->firstWhere('id', $this->level) : null;

        [$rows, $hasMore] = match (true) {
            $level !== null => $this->itemsInLevel($branchId, $level),
            $this->low => $this->lowStock($branchId),
            default => $this->products($branchId),
        };

        return view('livewire.ops.stock.index', [
            'branch' => CurrentBranch::get(),
            'levels' => $levels,
            'counts' => $counts,
            'lowCount' => $lowCount,
            'activeLevel' => $level,
            'rows' => $rows,
            'hasMore' => $hasMore,
            'mode' => $level !== null ? 'level' : ($this->low ? 'low' : 'products'),
        ])->title(__('Stock'));
    }

    /**
     * @return array{0: Collection<int, array{product: Product|null, available: int, total: int, min: int, next_expiry: string|null}>, 1: bool}
     */
    private function products(int $branchId): array
    {
        $query = StockOnHand::query()
            ->with('product')
            ->where('branch_id', $branchId)
            ->whereHas('product', fn ($q) => $q->search($this->search))
            ->orderByDesc('qty_available');

        $rows = $query->limit($this->perPage + 1)->get();
        $products = $rows->take($this->perPage);

        $nextExpiry = $this->nextExpiry($branchId, $products->pluck('product_id')->all());

        return [$products->map(fn (StockOnHand $row) => [
            'product' => $row->product,
            'available' => $row->qty_available,
            'total' => $row->qty_total,
            'min' => $row->product->minStockFor($branchId),
            'next_expiry' => $nextExpiry[$row->product_id] ?? null,
        ]), $rows->count() > $this->perPage];
    }

    /**
     * @return array{0: Collection<int, array{product: Product|null, available: int, total: int, min: int, next_expiry: null}>, 1: bool}
     */
    private function lowStock(int $branchId): array
    {
        $rows = LowStock::query()->with('product')->where('branch_id', $branchId)
            ->whereHas('product', fn ($q) => $q->search($this->search))
            ->orderByDesc('shortage')
            ->limit($this->perPage + 1)
            ->get();

        return [$rows->take($this->perPage)->map(fn (LowStock $row) => [
            'product' => $row->product,
            'available' => $row->qty_available,
            'total' => $row->qty_total,
            'min' => $row->min_stock,
            'next_expiry' => null,
        ]), $rows->count() > $this->perPage];
    }

    /**
     * Units and lots of the branch in one expiry level, soonest first.
     *
     * @return array{0: Collection<int, array<string, mixed>>, 1: bool}
     */
    private function itemsInLevel(int $branchId, ExpiryLevel $level): array
    {
        $query = app(StockQuery::class);
        $search = fn ($q) => $q->whereHas('product', fn ($p) => $p->search($this->search));

        $units = $query->whereExpiryLevel(StockUnit::query()->with(['product', 'location']), $level)
            ->where('branch_id', $branchId)
            ->whereIn('status', StockStatus::onHand())
            ->when($this->search !== '', $search)
            ->orderBy('expiry_date')
            ->limit($this->perPage + 1)
            ->get()
            ->map(fn (StockUnit $u) => ['type' => 'unit', 'product' => $u->product, 'code' => $u->serial, 'lot' => $u->lot, 'expiry' => $u->expiry_date, 'quantity' => 1, 'where' => $u->locationLabel(), 'url' => route('ops.stock.unit', $u)]);

        $batches = $query->whereExpiryLevel(StockBatch::query()->with(['product', 'location']), $level)
            ->where('branch_id', $branchId)
            ->where('quantity', '>', 0)
            ->when($this->search !== '', $search)
            ->orderBy('expiry_date')
            ->limit($this->perPage + 1)
            ->get()
            ->map(fn (StockBatch $b) => ['type' => 'lot', 'product' => $b->product, 'code' => $b->lot ?? '—', 'lot' => $b->lot, 'expiry' => $b->expiry_date, 'quantity' => $b->quantity, 'where' => $b->locationLabel(), 'url' => route('ops.stock.product', $b->product)]);

        $items = $units->concat($batches)->sortBy(fn ($i) => $i['expiry']->timestamp ?? PHP_INT_MAX)->values();

        return [$items->take($this->perPage), $items->count() > $this->perPage];
    }

    /**
     * Soonest expiry still on the shelf per product (for the card chip).
     *
     * @param  list<int>  $productIds
     * @return array<int, string>
     */
    private function nextExpiry(int $branchId, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $units = StockUnit::query()->where('branch_id', $branchId)->where('status', StockStatus::InStock)
            ->whereIn('product_id', $productIds)->whereNotNull('expiry_date')
            ->groupBy('product_id')->select('product_id', DB::raw('MIN(expiry_date) AS next'))->pluck('next', 'product_id');

        $batches = StockBatch::query()->where('branch_id', $branchId)->where('quantity', '>', 0)
            ->whereIn('product_id', $productIds)->whereNotNull('expiry_date')
            ->groupBy('product_id')->select('product_id', DB::raw('MIN(expiry_date) AS next'))->pluck('next', 'product_id');

        $next = [];

        foreach ([$units, $batches] as $rows) {
            foreach ($rows as $productId => $date) {
                $date = substr((string) $date, 0, 10);
                $next[(int) $productId] = isset($next[(int) $productId]) ? min($next[(int) $productId], $date) : $date;
            }
        }

        return $next;
    }

    private function user(): User
    {
        /** @var User */
        return Auth::user();
    }
}
