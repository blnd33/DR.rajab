<?php

namespace App\Services\Imports\Handlers;

use App\Enums\LocationType;
use App\Enums\MovementType;
use App\Exceptions\Domain\StockRuleViolation;
use App\Models\Branch;
use App\Models\Currency;
use App\Models\DataImport;
use App\Models\DataImportRow;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockUnit;
use App\Models\User;
use App\Services\Expiry\ExpiryClassifier;
use App\Services\Imports\CellParser;
use App\Services\Imports\Contracts\RowHandler;
use App\Services\Imports\RowFailed;
use App\Services\Imports\RowResult;
use App\Services\Permissions\BranchScopeResolver;
use App\Services\Stock\Data\ReceiveLine;
use App\Services\Stock\StockMovementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;

/**
 * Template B — stock (serials and lots). New serials / lots are received as "Excel import"
 * movements. Existing serials can have lot, expiry and cost updated. Excel can never move or
 * consume stock: no branch or status changes, and rows touching used/sold units are rejected.
 * A lot already in stock must have the same quantity (changes go through a stock count).
 */
class StockRowHandler implements RowHandler
{
    /** @var array<string, Branch> lower(code) → branch */
    private array $branches = [];

    /** @var list<int>|null null = all */
    private ?array $allowedBranchIds = [];

    private ?int $defaultBranchId = null;

    /** @var list<string> */
    private array $currencies = [];

    /** @var array<string, int> serial → row number */
    private array $seenSerials = [];

    /** @var array<string, int> lot bucket key → row number */
    private array $seenBuckets = [];

    public function __construct(
        private readonly StockMovementService $stock,
        private readonly BranchScopeResolver $scope,
    ) {}

    public function columns(): array
    {
        return [
            'sku' => 'SKU',
            'serial' => 'Serial',
            'lot' => 'Lot',
            'expiry_date' => 'Expiry date',
            'quantity' => 'Quantity',
            'branch' => 'Station',
            'purchase_price' => 'Purchase price',
            'currency' => 'Currency',
        ];
    }

    public function requiredColumns(): array
    {
        return ['sku'];
    }

    public function example(): array
    {
        return [
            'sku' => 'KN-TIB-5',
            'serial' => 'SN-000123',
            'lot' => 'L2401',
            'expiry_date' => Carbon::now()->addYears(2)->format('Y-m-d'),
            'quantity' => 1,
            'branch' => 'DHK',
            'purchase_price' => 850,
            'currency' => 'USD',
        ];
    }

    public function instructions(): array
    {
        return [
            __('One row per serial unit, or one row per lot (with its quantity).'),
            __('Serial-tracked products: Serial is required and Quantity is 1. Existing serials are updated (lot, expiry, price) — never moved.'),
            __('Lot-tracked products: leave Serial empty and fill Quantity. A lot that is already in stock must have the same quantity; quantity changes go through a stock count.'),
            __('Expiry date: YYYY-MM-DD (or an Excel date). Expired items are imported with a warning and cannot be used.'),
            __('Station: the station code (DHK, EBL…). Excel cannot move stock between stations or change used, sold or returned units.'),
            __('Purchase price + Currency: the true unit cost. Empty = the product\'s default purchase price.'),
        ];
    }

    public function begin(DataImport $import, User $user): void
    {
        $this->seenSerials = [];
        $this->seenBuckets = [];
        $this->defaultBranchId = $import->branch_id;
        $this->allowedBranchIds = $this->scope->branchIdsFor($user, 'stock.import');
        $this->currencies = Currency::query()->pluck('code')->all();
        $this->branches = Branch::query()->get()->keyBy(fn (Branch $b) => CellParser::key($b->code))->all();
    }

    public function check(array $row, int $rowNumber): RowResult
    {
        $result = new RowResult;

        $sku = CellParser::text($row['sku'] ?? null);
        $product = $sku !== null ? Product::query()->where('sku', $sku)->first() : null;

        if ($sku === null) {
            return $result->error(__('SKU is empty.'));
        }

        if ($product === null) {
            return $result->error(__('No product with SKU :sku. Import the product catalog first.', ['sku' => $sku]));
        }

        if (! $product->is_active) {
            $result->error(__(':sku is inactive.', ['sku' => $sku]));
        }

        $branch = $this->branch($row, $result);
        [$expiry, $expiryValid] = CellParser::date($row['expiry_date'] ?? null);

        if (! $expiryValid) {
            $result->error(__('Expiry date is not a real date — use YYYY-MM-DD.'));
        }

        [$quantity, $quantityValid] = CellParser::integer($row['quantity'] ?? null);

        if (! $quantityValid) {
            $result->error(__('Quantity must be a whole number.'));
        }

        [$price, $priceValid] = CellParser::decimal($row['purchase_price'] ?? null);

        if (! $priceValid) {
            $result->error(__('Purchase price must be a number (0 or more).'));
        }

        $currency = CellParser::text($row['currency'] ?? null);
        $currency = $currency !== null ? mb_strtoupper($currency) : null;

        if ($currency !== null && ! in_array($currency, $this->currencies, true)) {
            $result->error(__('Currency ":value" is not valid — use USD, EUR or IQD.', ['value' => $currency]));
        }

        if ($expiry !== null && Carbon::parse($expiry)->lte(ExpiryClassifier::today())) {
            $result->warn(__('Already expired (:date): it will be imported but cannot be used.', ['date' => $expiry]));
        }

        $serial = CellParser::text($row['serial'] ?? null);
        $lot = CellParser::text($row['lot'] ?? null);

        foreach (['serial' => $serial, 'lot' => $lot] as $field => $value) {
            if ($value !== null && mb_strlen($value) > 64) {
                $result->error($field === 'serial' ? __('Serial is longer than 64 characters.') : __('Lot is longer than 64 characters.'));
            }
        }

        $context = compact('product', 'branch', 'serial', 'lot', 'expiry', 'quantity', 'price', 'currency');

        if ($product->isSerialTracked()) {
            $this->checkSerial($result, $rowNumber, $context);
        } else {
            $this->checkLot($result, $rowNumber, $context);
        }

        return $result;
    }

    public function apply(DataImportRow $row, DataImport $import, User $user): void
    {
        $data = $row->normalized ?? [];
        $reason = __('Excel import #:id, row :row', ['id' => $import->id, 'row' => $row->row_number]);

        try {
            match ($data['mode'] ?? null) {
                'create' => $this->applyCreate($row, $import, $user, $data, $reason),
                'update_unit' => $this->applyUnitUpdate($row, $import, $user, $data, $reason),
                'update_batch' => $this->applyBatchUpdate($row, $import, $user, $data, $reason),
                default => throw new RowFailed(__('Nothing to import on this row.')),
            };
        } catch (StockRuleViolation|AuthorizationException $e) {
            throw new RowFailed($e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $c
     */
    private function checkSerial(RowResult $result, int $rowNumber, array $c): void
    {
        /** @var Product $product */
        $product = $c['product'];
        $serial = $c['serial'];

        if ($serial === null) {
            $result->error(__(':sku is serial-tracked: the Serial column is required.', ['sku' => $product->sku]));

            return;
        }

        if ($c['quantity'] !== null && $c['quantity'] !== 1) {
            $result->error(__('A serial-tracked unit always has quantity 1.'));
        }

        if (isset($this->seenSerials[$serial])) {
            $result->error(__('Serial :serial is also on row :row of this file.', ['serial' => $serial, 'row' => $this->seenSerials[$serial]]));

            return;
        }

        $this->seenSerials[$serial] = $rowNumber;

        /** @var StockUnit|null $unit */
        $unit = StockUnit::query()->with('branch')->where('serial', $serial)->first();

        if ($unit === null) {
            $this->prepareCreate($result, $c);

            return;
        }

        $result->targetType = StockUnit::class;
        $result->targetId = $unit->id;

        if ($unit->product_id !== $product->id) {
            $result->error(__('Serial :serial belongs to another product (:sku).', ['serial' => $serial, 'sku' => $unit->product->sku]));

            return;
        }

        if (! $unit->status->isOnHand()) {
            $result->error(__('Serial :serial is :status — Excel cannot change used, sold or returned units.', ['serial' => $serial, 'status' => $unit->status->getLabel()]));

            return;
        }

        if ($c['branch'] !== null && $unit->branch_id !== $c['branch']->id) {
            $result->error(__('Serial :serial is in station :branch. Excel cannot move stock — use a transfer.', ['serial' => $serial, 'branch' => $unit->branch->code]));

            return;
        }

        $changes = [];

        if ($c['lot'] !== null) {
            $result->change('lot', $unit->lot, $c['lot']);
            $changes['lot'] = $c['lot'];
        }

        if ($c['expiry'] !== null) {
            $result->change('expiry_date', $unit->expiry_date?->toDateString(), $c['expiry']);
            $changes['expiry_date'] = $c['expiry'];
        }

        if ($c['price'] !== null) {
            $result->change('purchase_price', $unit->base_unit_cost, $c['price']);
            $changes['base_unit_cost'] = $c['price'];
        }

        if ($c['currency'] !== null) {
            $result->change('currency', $unit->cost_currency, $c['currency']);
            $changes['cost_currency'] = $c['currency'];
        }

        $result->normalized = ['mode' => 'update_unit', 'unit_id' => $unit->id, 'changes' => $changes];
    }

    /**
     * @param  array<string, mixed>  $c
     */
    private function checkLot(RowResult $result, int $rowNumber, array $c): void
    {
        /** @var Product $product */
        $product = $c['product'];

        if ($c['serial'] !== null) {
            $result->error(__(':sku is lot-tracked: leave Serial empty.', ['sku' => $product->sku]));

            return;
        }

        if ($c['quantity'] === null || $c['quantity'] < 1) {
            $result->error(__('Quantity is required (1 or more).'));

            return;
        }

        if ($c['branch'] === null) {
            return;
        }

        $bucketKey = implode('|', [$product->id, $c['branch']->id, $c['lot'] ?? '', $c['expiry'] ?? '']);

        if (isset($this->seenBuckets[$bucketKey])) {
            $result->error(__('The same product, lot and expiry is also on row :row of this file. Put the total quantity on one row.', ['row' => $this->seenBuckets[$bucketKey]]));

            return;
        }

        $this->seenBuckets[$bucketKey] = $rowNumber;

        $existing = StockBatch::query()
            ->where('product_id', $product->id)
            ->where('branch_id', $c['branch']->id)
            ->where('location_type', LocationType::Shelf)
            ->where('quantity', '>', 0)
            ->when($c['lot'] === null, fn ($q) => $q->whereNull('lot'), fn ($q) => $q->where('lot', $c['lot']))
            ->when($c['expiry'] === null, fn ($q) => $q->whereNull('expiry_date'), fn ($q) => $q->where('expiry_date', $c['expiry']))
            ->get();

        if ($existing->isEmpty()) {
            $this->prepareCreate($result, $c);

            return;
        }

        $onHand = (int) $existing->sum('quantity');

        if ($onHand !== $c['quantity']) {
            $result->error(__('This lot is already in stock with quantity :qty. Excel cannot change quantities — use Receive stock for new deliveries or a stock count for corrections.', ['qty' => $onHand]));

            return;
        }

        $changes = [];

        if ($existing->count() === 1) {
            /** @var StockBatch $batch */
            $batch = $existing->first();
            $result->targetType = StockBatch::class;
            $result->targetId = $batch->id;

            if ($c['price'] !== null) {
                $result->change('purchase_price', $batch->base_unit_cost, $c['price']);
                $changes['base_unit_cost'] = $c['price'];
            }

            if ($c['currency'] !== null) {
                $result->change('currency', $batch->cost_currency, $c['currency']);
                $changes['cost_currency'] = $c['currency'];
            }

            $result->normalized = ['mode' => 'update_batch', 'batch_id' => $batch->id, 'changes' => $changes];
        } elseif ($c['price'] !== null) {
            $result->warn(__('This lot is stored at several costs; the price on this row is not applied.'));
        }
    }

    /**
     * @param  array<string, mixed>  $c
     */
    private function prepareCreate(RowResult $result, array $c): void
    {
        /** @var Product $product */
        $product = $c['product'];
        $result->isNew = true;

        if ($c['branch'] === null) {
            return;
        }

        if ($product->requires_expiry && $c['expiry'] === null) {
            $result->error(__(':sku needs an expiry date.', ['sku' => $product->sku]));
        }

        $price = $c['price'] ?? $product->purchase_price;

        if ($price === null) {
            $result->warn(__('No purchase price: the unit cost is recorded as 0.'));
        }

        $result->normalized = [
            'mode' => 'create',
            'branch_id' => $c['branch']->id,
            'line' => [
                'product_id' => $product->id,
                'quantity' => $product->isSerialTracked() ? 1 : $c['quantity'],
                'serial' => $c['serial'],
                'lot' => $c['lot'],
                'expiry_date' => $c['expiry'],
                'unit_cost' => $price ?? '0',
                'cost_currency' => $c['currency'] ?? $product->purchase_currency ?? 'USD',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function branch(array $row, RowResult $result): ?Branch
    {
        $code = CellParser::text($row['branch'] ?? null);

        $branch = $code !== null
            ? ($this->branches[CellParser::key($code)] ?? null)
            : collect($this->branches)->first(fn (Branch $b) => $b->id === $this->defaultBranchId);

        if ($branch === null) {
            $result->error($code === null
                ? __('Station is empty.')
                : __('Station :code does not exist.', ['code' => $code]));

            return null;
        }

        if (! $branch->isActive()) {
            $result->error(__('Station :code is closed.', ['code' => $branch->code]));

            return null;
        }

        if ($this->allowedBranchIds !== null && ! in_array($branch->id, $this->allowedBranchIds, true)) {
            $result->error(__('You may not import stock into station :code.', ['code' => $branch->code]));

            return null;
        }

        return $branch;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyCreate(DataImportRow $row, DataImport $import, User $user, array $data, string $reason): void
    {
        $branch = Branch::query()->findOrFail($data['branch_id']);

        $result = $this->stock->receive(
            $branch,
            [ReceiveLine::fromArray($data['line'])],
            $user,
            MovementType::Import,
            $import,
            $reason,
            'stock.import',
        );

        $target = $result->units[0] ?? $result->batches[0] ?? null;

        if ($target !== null) {
            $row->target()->associate($target);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyUnitUpdate(DataImportRow $row, DataImport $import, User $user, array $data, string $reason): void
    {
        $unit = StockUnit::query()->findOrFail($data['unit_id']);
        $this->stock->correctUnit($unit, $data['changes'] ?? [], $reason, $user, $import, 'stock.import');
        $row->target()->associate($unit);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyBatchUpdate(DataImportRow $row, DataImport $import, User $user, array $data, string $reason): void
    {
        $batch = StockBatch::query()->findOrFail($data['batch_id']);
        $this->stock->correctBatch($batch, $data['changes'] ?? [], $reason, $user, $import, 'stock.import');
        $row->target()->associate($batch);
    }
}
