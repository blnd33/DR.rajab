<?php

namespace App\Services\Stock;

use App\Enums\LocationType;
use App\Enums\MovementType;
use App\Enums\StockStatus;
use App\Exceptions\Domain\StockRuleViolation;
use App\Models\Branch;
use App\Models\BranchLocation;
use App\Models\Currency;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockMovement;
use App\Models\StockUnit;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Expiry\ExpiryClassifier;
use App\Services\Permissions\BranchScopeResolver;
use App\Services\Stock\Data\ReceiveLine;
use App\Services\Stock\Data\ReceiveResult;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * THE only writer of stock. Every public method:
 *   1. checks the permission in the branch ("what" × "where"),
 *   2. runs in DB::transaction with lockForUpdate on the rows it changes,
 *   3. writes stock_movements rows (one movement_group per operation).
 *
 * Phase 1: receive (opening balance / Excel import), corrections, status override,
 * and the expiry guard used by every later "use" movement (surgery, sale, transfer).
 */
class StockMovementService
{
    /** Fields a correction may change on a unit. Branch and status never change here. */
    public const UNIT_CORRECTABLE = ['lot', 'expiry_date', 'production_date', 'base_unit_cost', 'cost_currency', 'supplier_id', 'branch_location_id'];

    /** Same for a lot bucket. Quantity changes need a stock count (Phase 2). */
    public const BATCH_CORRECTABLE = ['lot', 'expiry_date', 'production_date', 'base_unit_cost', 'cost_currency', 'supplier_id', 'branch_location_id'];

    /** Changing one of these moves the quantity to another bucket. */
    private const BATCH_KEY_FIELDS = ['lot', 'expiry_date', 'base_unit_cost', 'cost_currency', 'branch_location_id'];

    public function __construct(private readonly BranchScopeResolver $scope) {}

    /*
    |--------------------------------------------------------------------------
    | Stock in
    |--------------------------------------------------------------------------
    */

    /**
     * Put stock into a branch: one unit per serial line, quantity into a lot bucket per lot line.
     *
     * @param  list<ReceiveLine>  $lines
     */
    public function receive(
        Branch $branch,
        array $lines,
        User $by,
        MovementType $type = MovementType::OpeningBalance,
        ?Model $reference = null,
        ?string $reason = null,
        string $permission = 'stock.receive',
    ): ReceiveResult {
        $this->authorize($by, $permission, $branch);

        if (! $branch->isActive()) {
            throw new StockRuleViolation(__('Branch :code is closed. Reopen it before adding stock.', ['code' => $branch->code]));
        }

        if ($lines === []) {
            throw new StockRuleViolation(__('Add at least one item.'));
        }

        $products = Product::query()->whereIn('id', array_map(fn (ReceiveLine $l) => $l->productId, $lines))->get()->keyBy('id');
        $warnings = $this->validateLines($branch, $lines, $products);

        $group = (string) Str::uuid();
        $now = now();

        try {
            return DB::transaction(function () use ($branch, $lines, $by, $type, $reference, $reason, $products, $group, $now, $warnings) {
                $units = [];
                $batches = [];
                $quantity = 0;

                foreach ($lines as $line) {
                    /** @var Product $product */
                    $product = $products[$line->productId];
                    $cost = $this->money($line->unitCost);

                    if ($product->isSerialTracked()) {
                        $unit = $this->createUnit($branch, $product, $line, $cost, $now);

                        $this->record($group, $type, $by, $now, [
                            'product_id' => $product->id,
                            'stock_unit_id' => $unit->id,
                            'quantity' => 1,
                            'to_branch_id' => $branch->id,
                            'to_status' => StockStatus::InStock,
                            'to_location_type' => LocationType::Shelf,
                            'unit_cost' => $unit->unit_cost,
                            'cost_currency' => $unit->cost_currency,
                            'reason' => $reason,
                        ], $reference);

                        $units[] = $unit;
                        $quantity++;

                        continue;
                    }

                    $batch = $this->lockBucket([
                        'product_id' => $product->id,
                        'branch_id' => $branch->id,
                        'lot' => $line->lot,
                        'expiry_date' => $line->expiryDate,
                        'location_type' => LocationType::Shelf->value,
                        'branch_location_id' => $line->locationId,
                        'unit_cost' => $cost,
                        'cost_currency' => $line->costCurrency,
                    ], [
                        'production_date' => $line->productionDate,
                        'base_unit_cost' => $cost,
                        'landed_unit_cost' => '0',
                        'supplier_id' => $line->supplierId,
                    ]);

                    $batch->quantity += $line->quantity;
                    $batch->production_date ??= $line->productionDate;
                    $batch->supplier_id ??= $line->supplierId;
                    $batch->save();

                    $this->record($group, $type, $by, $now, [
                        'product_id' => $product->id,
                        'to_batch_id' => $batch->id,
                        'quantity' => $line->quantity,
                        'to_branch_id' => $branch->id,
                        'to_location_type' => LocationType::Shelf,
                        'unit_cost' => $batch->unit_cost,
                        'cost_currency' => $batch->cost_currency,
                        'reason' => $reason,
                    ], $reference);

                    $batches[] = $batch;
                    $quantity += $line->quantity;
                }

                return new ReceiveResult($group, $units, $batches, $quantity, $warnings);
            });
        } catch (UniqueConstraintViolationException) {
            // Someone else saved the same serial at the same moment.
            throw new StockRuleViolation(__('One of these serial numbers was just added by someone else. Scan it again to check.'), 'serial');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Corrections (with a reason, always on the ledger)
    |--------------------------------------------------------------------------
    */

    /**
     * Fix lot / expiry / cost / supplier / shelf of a unit that is still ours.
     *
     * @param  array<string, mixed>  $changes
     */
    public function correctUnit(
        StockUnit $unit,
        array $changes,
        string $reason,
        User $by,
        ?Model $reference = null,
        string $permission = 'stock.correct',
        MovementType $type = MovementType::CostAdjustment,
    ): ?StockMovement {
        $this->requireReason($reason);

        return DB::transaction(function () use ($unit, $changes, $reason, $by, $reference, $permission, $type) {
            /** @var StockUnit $unit */
            $unit = StockUnit::query()->with('branch')->lockForUpdate()->findOrFail($unit->id);
            $this->authorize($by, $permission, $unit->branch);

            if (! $unit->status->isOnHand()) {
                throw new StockRuleViolation(__('Unit :serial is :status. Only units still in our stock can be corrected.', [
                    'serial' => $unit->serial,
                    'status' => $unit->status->getLabel(),
                ]));
            }

            $changes = $this->normalizeChanges($changes, self::UNIT_CORRECTABLE, $unit->branch_id);
            $diff = $this->diff($unit, $changes);

            if ($diff === []) {
                return null;
            }

            $unit->fill($changes);

            if (array_key_exists('base_unit_cost', $changes)) {
                $unit->unit_cost = (string) BigDecimal::of($unit->base_unit_cost)->plus($unit->landed_unit_cost)->toScale(4);
            }

            $unit->lock_version++;
            $unit->save();

            $now = now();

            return $this->record((string) Str::uuid(), $type, $by, $now, [
                'product_id' => $unit->product_id,
                'stock_unit_id' => $unit->id,
                'quantity' => 1,
                'from_branch_id' => $unit->branch_id,
                'to_branch_id' => $unit->branch_id,
                'from_status' => $unit->status,
                'to_status' => $unit->status,
                'from_location_type' => $unit->location_type,
                'to_location_type' => $unit->location_type,
                'unit_cost' => $unit->unit_cost,
                'cost_currency' => $unit->cost_currency,
                'reason' => $reason,
                'details' => $diff,
            ], $reference);
        });
    }

    /**
     * Fix lot / expiry / cost / supplier / shelf of a lot bucket. When a key field changes
     * the whole quantity moves to the matching bucket (merging with it if it exists).
     *
     * @param  array<string, mixed>  $changes
     */
    public function correctBatch(
        StockBatch $batch,
        array $changes,
        string $reason,
        User $by,
        ?Model $reference = null,
        string $permission = 'stock.correct',
        MovementType $type = MovementType::CostAdjustment,
    ): ?StockMovement {
        $this->requireReason($reason);

        return DB::transaction(function () use ($batch, $changes, $reason, $by, $reference, $permission, $type) {
            /** @var StockBatch $source */
            $source = StockBatch::query()->with('branch')->lockForUpdate()->findOrFail($batch->id);
            $this->authorize($by, $permission, $source->branch);

            if ($source->quantity <= 0) {
                throw new StockRuleViolation(__('This lot has no quantity left to correct.'));
            }

            $changes = $this->normalizeChanges($changes, self::BATCH_CORRECTABLE, $source->branch_id);
            $diff = $this->diff($source, $changes);

            if ($diff === []) {
                return null;
            }

            $now = now();
            $group = (string) Str::uuid();
            $movesBucket = array_intersect(array_keys($diff), self::BATCH_KEY_FIELDS) !== [];

            if (! $movesBucket) {
                $source->fill($changes)->save();

                return $this->record($group, $type, $by, $now, [
                    'product_id' => $source->product_id,
                    'from_batch_id' => $source->id,
                    'to_batch_id' => $source->id,
                    'quantity' => $source->quantity,
                    'from_branch_id' => $source->branch_id,
                    'to_branch_id' => $source->branch_id,
                    'from_location_type' => $source->location_type,
                    'to_location_type' => $source->location_type,
                    'unit_cost' => $source->unit_cost,
                    'cost_currency' => $source->cost_currency,
                    'reason' => $reason,
                    'details' => $diff,
                ], $reference);
            }

            $new = array_merge([
                'lot' => $source->lot,
                'expiry_date' => $source->expiry_date?->toDateString(),
                'production_date' => $source->production_date?->toDateString(),
                'base_unit_cost' => $source->base_unit_cost,
                'cost_currency' => $source->cost_currency,
                'supplier_id' => $source->supplier_id,
                'branch_location_id' => $source->branch_location_id,
            ], $changes);

            $unitCost = (string) BigDecimal::of($new['base_unit_cost'])->plus($source->landed_unit_cost)->toScale(4);

            $target = $this->lockBucket([
                'product_id' => $source->product_id,
                'branch_id' => $source->branch_id,
                'lot' => $new['lot'],
                'expiry_date' => $new['expiry_date'],
                'location_type' => $source->location_type->value,
                'branch_location_id' => $new['branch_location_id'],
                'unit_cost' => $unitCost,
                'cost_currency' => $new['cost_currency'],
            ], [
                'production_date' => $new['production_date'],
                'base_unit_cost' => $new['base_unit_cost'],
                'landed_unit_cost' => $source->landed_unit_cost,
                'supplier_id' => $new['supplier_id'],
                'last_expiry_level_id' => $source->last_expiry_level_id,
            ]);

            $quantity = $source->quantity;
            $target->quantity += $quantity;
            $target->save();

            $source->quantity = 0;
            $source->save();

            return $this->record($group, $type, $by, $now, [
                'product_id' => $source->product_id,
                'from_batch_id' => $source->id,
                'to_batch_id' => $target->id,
                'quantity' => $quantity,
                'from_branch_id' => $source->branch_id,
                'to_branch_id' => $source->branch_id,
                'from_location_type' => $source->location_type,
                'to_location_type' => $target->location_type,
                'unit_cost' => $target->unit_cost,
                'cost_currency' => $target->cost_currency,
                'reason' => $reason,
                'details' => $diff,
            ], $reference);
        });
    }

    /**
     * Super Admin correction of a unit's status (e.g. a unit recorded wrongly).
     * Damage / loss / expiry go through the write-off flow (Phase 2), not here.
     */
    public function overrideStatus(StockUnit $unit, StockStatus $to, string $reason, User $by): StockMovement
    {
        $this->requireReason($reason);

        return DB::transaction(function () use ($unit, $to, $reason, $by) {
            /** @var StockUnit $unit */
            $unit = StockUnit::query()->with('branch')->lockForUpdate()->findOrFail($unit->id);
            $this->authorize($by, 'stock.adjust', $unit->branch);

            if ($unit->status === $to) {
                throw new StockRuleViolation(__('The unit is already :status.', ['status' => $to->getLabel()]));
            }

            $from = $unit->status;
            $fromLocation = $unit->location_type;

            $unit->status = $to;
            $unit->location_type = $to->locationType();

            if ($unit->location_type !== LocationType::Shelf) {
                $unit->branch_location_id = null;
            }

            $unit->lock_version++;
            $unit->save();

            return $this->record((string) Str::uuid(), MovementType::StatusOverride, $by, now(), [
                'product_id' => $unit->product_id,
                'stock_unit_id' => $unit->id,
                'quantity' => 1,
                'from_branch_id' => $unit->branch_id,
                'to_branch_id' => $unit->branch_id,
                'from_status' => $from,
                'to_status' => $to,
                'from_location_type' => $fromLocation,
                'to_location_type' => $unit->location_type,
                'unit_cost' => $unit->unit_cost,
                'cost_currency' => $unit->cost_currency,
                'reason' => $reason,
                'is_override' => true,
                'override_reason' => $reason,
            ]);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Expiry guard (used by surgery, sale and transfer movements)
    |--------------------------------------------------------------------------
    */

    /**
     * Expired (or "blocks usage") items can't be used, sold or transferred — unless the user
     * has stock.override_expired in that branch and gives a reason (logged on the movement).
     *
     * @return bool true when this use is an override
     */
    public function assertUsable(StockUnit|StockBatch $item, User $by, ?string $overrideReason = null): bool
    {
        if (! $item->isBlockedByExpiry()) {
            return false;
        }

        $what = $item instanceof StockUnit ? $item->serial : ($item->lot ?? '—');
        $level = $item->expiryLevel()->name ?? __('Expired');

        if (blank($overrideReason)) {
            throw new StockRuleViolation(__(':code is :level (expiry :date) and cannot be used.', [
                'code' => $what,
                'level' => $level,
                'date' => $item->expiry_date?->toDateString(),
            ]), 'expiry');
        }

        if (! $this->scope->allows($by, 'stock.override_expired', $item->branch_id)) {
            throw new StockRuleViolation(__('Only a user allowed to override expired stock can use :code.', ['code' => $what]), 'expiry');
        }

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Append one ledger row.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function record(string $group, MovementType $type, User $by, Carbon $at, array $attributes, ?Model $reference = null): StockMovement
    {
        $movement = new StockMovement(array_merge([
            'movement_group' => $group,
            'type' => $type,
            'user_id' => $by->id,
            'occurred_at' => $at,
            'is_override' => false,
        ], $attributes));

        if ($reference !== null) {
            $movement->reference()->associate($reference);
        }

        $movement->save();

        return $movement;
    }

    private function createUnit(Branch $branch, Product $product, ReceiveLine $line, string $cost, Carbon $now): StockUnit
    {
        if (StockUnit::query()->where('serial', $line->serial)->lockForUpdate()->exists()) {
            throw new StockRuleViolation(__('Serial :serial is already in the system.', ['serial' => $line->serial]), 'serial');
        }

        $unit = new StockUnit([
            'product_id' => $product->id,
            'serial' => $line->serial,
            'lot' => $line->lot,
            'expiry_date' => $line->expiryDate,
            'production_date' => $line->productionDate,
            'branch_id' => $branch->id,
            'status' => StockStatus::InStock,
            'location_type' => LocationType::Shelf,
            'branch_location_id' => $line->locationId,
            'base_unit_cost' => $cost,
            'landed_unit_cost' => '0',
            'unit_cost' => $cost,
            'cost_currency' => $line->costCurrency,
            'supplier_id' => $line->supplierId,
            'received_at' => $now,
        ]);

        $unit->last_expiry_level_id = $unit->expiryLevel()?->id;
        $unit->save();

        return $unit;
    }

    /**
     * Find a lot bucket by its key and lock it, or create it. Creation runs in a savepoint so a
     * concurrent insert of the same bucket (unique index) is retried as a read.
     *
     * @param  array<string, mixed>  $key
     * @param  array<string, mixed>  $defaults
     */
    private function lockBucket(array $key, array $defaults): StockBatch
    {
        if (($batch = $this->findBucket($key)) !== null) {
            return $batch;
        }

        try {
            return DB::transaction(function () use ($key, $defaults) {
                $batch = new StockBatch(array_merge($key, $defaults, ['quantity' => 0]));
                $batch->last_expiry_level_id ??= $batch->expiryLevel()?->id;
                $batch->save();

                return StockBatch::query()->lockForUpdate()->findOrFail($batch->id);
            });
        } catch (UniqueConstraintViolationException) {
            // The other transaction's bucket is committed now: read and lock it.
            return $this->findBucket($key) ?? throw new StockRuleViolation(__('The stock changed while saving. Please try again.'));
        }
    }

    /**
     * Reads the database each call (another transaction may have created the bucket).
     *
     * @phpstan-impure
     *
     * @param  array<string, mixed>  $key  exact bucket key (NULL = IS NULL)
     */
    private function findBucket(array $key): ?StockBatch
    {
        $query = StockBatch::query()->lockForUpdate();

        foreach ($key as $column => $value) {
            $value === null ? $query->whereNull($column) : $query->where($column, $value);
        }

        return $query->first();
    }

    /**
     * @param  list<ReceiveLine>  $lines
     * @param  Collection<int, Product>  $products
     * @return list<string> warnings
     */
    private function validateLines(Branch $branch, array $lines, $products): array
    {
        $warnings = [];
        $serials = [];
        $currencies = Currency::query()->pluck('code')->all();
        $locationIds = BranchLocation::query()->where('branch_id', $branch->id)->pluck('id')->all();

        foreach ($lines as $index => $line) {
            $row = $index + 1;
            $product = $products[$line->productId] ?? null;

            if ($product === null) {
                throw new StockRuleViolation(__('Line :row: product not found.', ['row' => $row]), "lines.{$index}.product_id");
            }

            if (! $product->is_active) {
                throw new StockRuleViolation(__('Line :row: :sku is inactive.', ['row' => $row, 'sku' => $product->sku]), "lines.{$index}.product_id");
            }

            if ($product->isSerialTracked()) {
                if (blank($line->serial)) {
                    throw new StockRuleViolation(__('Line :row: :sku is serial-tracked — scan or type the serial number.', ['row' => $row, 'sku' => $product->sku]), "lines.{$index}.serial");
                }

                if ($line->quantity !== 1) {
                    throw new StockRuleViolation(__('Line :row: a serial-tracked unit always has quantity 1.', ['row' => $row]), "lines.{$index}.quantity");
                }

                if (isset($serials[$line->serial])) {
                    throw new StockRuleViolation(__('Serial :serial is in the list twice.', ['serial' => $line->serial]), "lines.{$index}.serial");
                }

                $serials[$line->serial] = true;
            } else {
                if ($line->quantity < 1) {
                    throw new StockRuleViolation(__('Line :row: quantity must be at least 1.', ['row' => $row]), "lines.{$index}.quantity");
                }

                if (filled($line->serial)) {
                    throw new StockRuleViolation(__('Line :row: :sku is lot-tracked — it has no serial numbers.', ['row' => $row, 'sku' => $product->sku]), "lines.{$index}.serial");
                }
            }

            if ($line->serial !== null && mb_strlen($line->serial) > 64) {
                throw new StockRuleViolation(__('Line :row: the serial number is too long (64 characters at most).', ['row' => $row]), "lines.{$index}.serial");
            }

            if ($line->lot !== null && mb_strlen($line->lot) > 64) {
                throw new StockRuleViolation(__('Line :row: the lot number is too long (64 characters at most).', ['row' => $row]), "lines.{$index}.lot");
            }

            foreach (['expiryDate' => 'expiry_date', 'productionDate' => 'production_date'] as $property => $field) {
                $value = $line->{$property};

                if ($value !== null && ! $this->isDate($value)) {
                    throw new StockRuleViolation(__('Line :row: the date must be YYYY-MM-DD.', ['row' => $row]), "lines.{$index}.{$field}");
                }
            }

            if ($product->requires_expiry && $line->expiryDate === null) {
                throw new StockRuleViolation(__('Line :row: :sku needs an expiry date.', ['row' => $row, 'sku' => $product->sku]), "lines.{$index}.expiry_date");
            }

            if ($line->expiryDate !== null && Carbon::parse($line->expiryDate)->lte(ExpiryClassifier::today())) {
                $warnings[] = __('Line :row: :code is already expired (:date). It is recorded but cannot be used.', [
                    'row' => $row,
                    'code' => $line->serial ?? $line->lot ?? $product->sku,
                    'date' => $line->expiryDate,
                ]);
            }

            if (! in_array($line->costCurrency, $currencies, true)) {
                throw new StockRuleViolation(__('Line :row: unknown currency :currency.', ['row' => $row, 'currency' => $line->costCurrency]), "lines.{$index}.cost_currency");
            }

            $this->money($line->unitCost);

            if ($line->locationId !== null && ! in_array($line->locationId, $locationIds, true)) {
                throw new StockRuleViolation(__('Line :row: that shelf is not in branch :code.', ['row' => $row, 'code' => $branch->code]), "lines.{$index}.location_id");
            }

            if ($line->supplierId !== null && ! Supplier::query()->whereKey($line->supplierId)->exists()) {
                throw new StockRuleViolation(__('Line :row: supplier not found.', ['row' => $row]), "lines.{$index}.supplier_id");
            }
        }

        return $warnings;
    }

    /**
     * @param  array<string, mixed>  $changes
     * @param  list<string>  $allowed
     * @return array<string, mixed>
     */
    private function normalizeChanges(array $changes, array $allowed, int $branchId): array
    {
        $changes = array_intersect_key($changes, array_flip($allowed));

        foreach ($changes as $field => $value) {
            $value = is_string($value) ? trim($value) : $value;
            $changes[$field] = $value === '' ? null : $value;
        }

        foreach (['expiry_date', 'production_date'] as $field) {
            if (isset($changes[$field]) && ! $this->isDate((string) $changes[$field])) {
                throw new StockRuleViolation(__('The date must be YYYY-MM-DD.'), $field);
            }
        }

        if (array_key_exists('base_unit_cost', $changes)) {
            $changes['base_unit_cost'] = $this->money((string) ($changes['base_unit_cost'] ?? '0'));
        }

        if (array_key_exists('cost_currency', $changes) && ! Currency::query()->whereKey($changes['cost_currency'])->exists()) {
            throw new StockRuleViolation(__('Unknown currency :currency.', ['currency' => (string) $changes['cost_currency']]), 'cost_currency');
        }

        if (isset($changes['branch_location_id'])
            && ! BranchLocation::query()->whereKey($changes['branch_location_id'])->where('branch_id', $branchId)->exists()) {
            throw new StockRuleViolation(__('That shelf is not in this branch.'), 'branch_location_id');
        }

        foreach (['supplier_id', 'branch_location_id'] as $field) {
            if (isset($changes[$field])) {
                $changes[$field] = (int) $changes[$field];
            }
        }

        return $changes;
    }

    /**
     * Field → {old, new} for values that really change (dates and money compared by value).
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function diff(StockUnit|StockBatch $model, array $changes): array
    {
        $diff = [];

        foreach ($changes as $field => $new) {
            $old = $model->getAttribute($field);

            $oldComparable = match (true) {
                $old instanceof Carbon => $old->toDateString(),
                in_array($field, ['base_unit_cost'], true) && $old !== null => (string) BigDecimal::of($old)->toScale(4),
                default => $old,
            };

            if ((string) $oldComparable !== (string) $new) {
                $diff[$field] = ['old' => $oldComparable, 'new' => $new];
            }
        }

        return $diff;
    }

    private function money(string $value): string
    {
        try {
            $amount = BigDecimal::of(str_replace(',', '', trim($value)));
        } catch (MathException) {
            throw new StockRuleViolation(__('":value" is not a valid amount.', ['value' => $value]), 'unit_cost');
        }

        if ($amount->isNegative()) {
            throw new StockRuleViolation(__('A cost cannot be negative.'), 'unit_cost');
        }

        return (string) $amount->toScale(4, RoundingMode::HalfUp);
    }

    private function isDate(string $value): bool
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private function requireReason(string $reason): void
    {
        if (blank($reason)) {
            throw new StockRuleViolation(__('A reason is required.'), 'reason');
        }
    }

    private function authorize(User $by, string $permission, Branch|int $branch): void
    {
        $branchId = $branch instanceof Branch ? $branch->id : $branch;

        if (! $this->scope->allows($by, $permission, $branchId)) {
            $code = $branch instanceof Branch ? $branch->code : (string) $branchId;

            throw new AuthorizationException(__('You are not allowed to do this in branch :code.', ['code' => $code]));
        }
    }
}
