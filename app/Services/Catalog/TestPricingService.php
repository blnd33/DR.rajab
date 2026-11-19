<?php

namespace App\Services\Catalog;

use App\Enums\StockStatus;
use App\Exceptions\Domain\StockRuleViolation;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockUnit;
use App\Models\User;
use App\Services\Stock\StockMovementService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

/**
 * Test prices for trying the reports (asked by the client for the test site): every product
 * that has stock, in SKU order, costs 1 USD, 2 USD, 3 USD…; its selling price is the cost plus
 * the markup (20 % by default). The pieces already in stock get the same cost through the normal
 * stock correction, so every change is on the ledger with its reason.
 *
 * A piece carrying landed costs keeps them: in USD its base cost is lowered so the total is
 * still the test cost; in another currency it is left as it is (and counted as skipped).
 */
class TestPricingService
{
    public const CURRENCY = 'USD';

    public const REASON = 'Test prices: cost 1, 2, 3… USD by SKU, selling price = cost + markup';

    public function __construct(
        private readonly ProductService $products,
        private readonly StockMovementService $stock,
    ) {}

    /**
     * The products that have stock now, in SKU order, with their test cost and selling price.
     *
     * @return Collection<int, array{product: Product, cost: string, selling: string}>
     */
    public function plan(int $start = 1, int $step = 1, string $markupPercent = '20'): Collection
    {
        $withUnits = StockUnit::query()->whereIn('status', StockStatus::onHand())->select('product_id');
        $withLots = StockBatch::query()->where('quantity', '>', 0)->select('product_id');
        $factor = BigDecimal::one()->plus(BigDecimal::of($markupPercent)->dividedBy(100, 8, RoundingMode::HalfUp));

        return Product::query()
            ->where(fn ($q) => $q->whereIn('id', $withUnits)->orWhereIn('id', $withLots))
            ->orderBy('sku')->orderBy('id')
            ->get()
            ->values()
            ->map(function (Product $product, int $i) use ($start, $step, $factor) {
                $cost = BigDecimal::of($start + $i * $step)->toScale(4);

                return [
                    'product' => $product,
                    'cost' => (string) $cost,
                    'selling' => (string) $cost->multipliedBy($factor)->toScale(4, RoundingMode::HalfUp),
                ];
            });
    }

    /**
     * @return array{products: int, units: int, lots: int, skipped: int, last_cost: ?string}
     */
    public function apply(User $by, int $start = 1, int $step = 1, string $markupPercent = '20', ?callable $progress = null): array
    {
        if (! $by->can('products.manage') || ! $by->can('stock.correct')) {
            throw new AuthorizationException(__('You are not allowed to do this.'));
        }

        if ($start < 0 || $step < 1 || BigDecimal::of($markupPercent)->isNegative()) {
            throw new \InvalidArgumentException('Start must be 0 or more, step 1 or more and the markup not negative.');
        }

        $summary = ['products' => 0, 'units' => 0, 'lots' => 0, 'skipped' => 0, 'last_cost' => null];

        foreach ($this->plan($start, $step, $markupPercent) as $row) {
            /** @var Product $product */
            $product = $row['product'];

            $this->products->update($product, [
                'purchase_price' => $row['cost'],
                'purchase_currency' => self::CURRENCY,
                'selling_price' => $row['selling'],
                'selling_currency' => self::CURRENCY,
            ]);

            $unitIds = StockUnit::query()->where('product_id', $product->id)->whereIn('status', StockStatus::onHand())->orderBy('id')->pluck('id');

            foreach ($unitIds as $id) {
                $unit = StockUnit::query()->findOrFail($id);
                $changes = $this->costChanges($row['cost'], (string) $unit->landed_unit_cost, $unit->cost_currency);

                if ($changes === null) {
                    $summary['skipped']++;

                    continue;
                }

                try {
                    $this->stock->correctUnit($unit, $changes, self::REASON, $by);
                    $summary['units']++;
                } catch (StockRuleViolation) {
                    $summary['skipped']++;
                }
            }

            $lotIds = StockBatch::query()->where('product_id', $product->id)->where('quantity', '>', 0)->orderBy('id')->pluck('id');

            foreach ($lotIds as $id) {
                $lot = StockBatch::query()->find($id);

                // An earlier correction may already have merged this lot into another bucket.
                if ($lot === null || $lot->quantity <= 0) {
                    continue;
                }

                $changes = $this->costChanges($row['cost'], (string) $lot->landed_unit_cost, $lot->cost_currency);

                if ($changes === null) {
                    $summary['skipped']++;

                    continue;
                }

                try {
                    $this->stock->correctBatch($lot, $changes, self::REASON, $by);
                    $summary['lots']++;
                } catch (StockRuleViolation) {
                    $summary['skipped']++;
                }
            }

            $summary['products']++;
            $summary['last_cost'] = $row['cost'];

            if ($progress !== null) {
                $progress($product, $row['cost'], $row['selling']);
            }
        }

        return $summary;
    }

    /**
     * The base cost and currency that make the piece cost exactly the test cost in USD, or
     * null when it carries landed costs in another currency (left as it is).
     *
     * @return array{base_unit_cost: string, cost_currency: string}|null
     */
    private function costChanges(string $cost, string $landed, string $currency): ?array
    {
        $landed = BigDecimal::of($landed === '' ? '0' : $landed);

        if ($landed->isZero()) {
            return ['base_unit_cost' => $cost, 'cost_currency' => self::CURRENCY];
        }

        if ($currency !== self::CURRENCY) {
            return null;
        }

        $base = BigDecimal::of($cost)->minus($landed);

        return ['base_unit_cost' => (string) ($base->isNegative() ? BigDecimal::zero()->toScale(4) : $base->toScale(4)), 'cost_currency' => self::CURRENCY];
    }
}
