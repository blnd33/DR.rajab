<?php

namespace App\Services\Movement;

use App\Enums\CountLineResolution;
use App\Enums\CountLineResult;
use App\Enums\IssueSeverity;
use App\Enums\IssueType;
use App\Enums\LocationType;
use App\Enums\MovementType;
use App\Enums\ScanContext;
use App\Enums\StockCountScope;
use App\Enums\StockCountStatus;
use App\Enums\StockStatus;
use App\Enums\WriteOffReason;
use App\Exceptions\Domain\StockRuleViolation;
use App\Models\Branch;
use App\Models\Category;
use App\Models\StockBatch;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\StockCountScan;
use App\Models\StockUnit;
use App\Models\User;
use App\Notifications\DocumentEvent;
use App\Services\Barcode\ScanResolver;
use App\Services\Barcode\ScanResultKind;
use App\Services\Issues\IssueService;
use App\Services\Numbering\DocumentNumberGenerator;
use App\Services\Permissions\BranchScopeResolver;
use App\Services\Stock\StockMovementService;
use App\Support\Msg;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stock counts (Module 16): expected snapshot → scans (idempotent, also from phones that
 * were offline) → submit → review each difference → close.
 */
class StockCountService
{
    public function __construct(
        private readonly StockMovementService $stock,
        private readonly BranchScopeResolver $scope,
        private readonly DocumentNumberGenerator $numbers,
        private readonly IssueService $issues,
        private readonly WriteOffService $writeOffs,
        private readonly Notifier $notifier,
    ) {}

    public function start(Branch $branch, StockCountScope $scope, User $by, ?int $categoryId = null, ?int $locationId = null, ?string $notes = null): StockCount
    {
        $this->authorize($by, 'stock_counts.start', $branch);

        if ($scope === StockCountScope::Category && $categoryId === null) {
            throw new StockRuleViolation(__('Choose the category to count.'), 'category_id');
        }

        if ($scope === StockCountScope::Location && $locationId === null) {
            throw new StockRuleViolation(__('Choose the shelf to count.'), 'branch_location_id');
        }

        return DB::transaction(function () use ($branch, $scope, $by, $categoryId, $locationId, $notes) {
            $count = StockCount::query()->create([
                'number' => $this->numbers->next('CNT', $branch),
                'branch_id' => $branch->id,
                'scope' => $scope,
                'category_id' => $scope === StockCountScope::Category ? $categoryId : null,
                'branch_location_id' => $scope === StockCountScope::Location ? $locationId : null,
                'status' => StockCountStatus::InProgress,
                'snapshot_at' => now(),
                'started_by' => $by->id,
                'started_at' => now(),
                'notes' => $notes,
            ]);

            /** @param Builder<StockUnit>|Builder<StockBatch> $query */
            $filter = function (Builder $query) use ($count): void {
                if ($count->scope === StockCountScope::Category) {
                    $ids = Category::query()->where('id', $count->category_id)->orWhere('parent_id', $count->category_id)->pluck('id');
                    $query->whereHas('product', fn ($p) => $p->whereIn('category_id', $ids));
                }

                if ($count->scope === StockCountScope::Location) {
                    $query->where('branch_location_id', $count->branch_location_id);
                }
            };

            $units = StockUnit::query()->where('branch_id', $branch->id)->where('status', StockStatus::InStock);
            $filter($units);
            $units->each(fn (StockUnit $unit) => $count->lines()->create([
                'product_id' => $unit->product_id,
                'stock_unit_id' => $unit->id,
                'expected_qty' => 1,
            ]));

            $batches = StockBatch::query()->where('branch_id', $branch->id)->where('location_type', LocationType::Shelf)->where('quantity', '>', 0);
            $filter($batches);
            $batches->each(fn (StockBatch $batch) => $count->lines()->create([
                'product_id' => $batch->product_id,
                'stock_batch_id' => $batch->id,
                'expected_qty' => $batch->quantity,
            ]));

            return $count;
        });
    }

    /**
     * Record one scan. Idempotent on $clientUuid: a phone that retries after being offline
     * never counts twice. Returns the line that was counted (null for an unknown code).
     */
    public function recordScan(StockCount $count, string $clientUuid, string $raw, User $by, int $quantity = 1, ?Carbon $scannedAt = null, ?string $device = null): ?StockCountLine
    {
        $this->authorize($by, 'stock_counts.scan', $count->branch_id);

        if (! Str::isUuid($clientUuid)) {
            throw new StockRuleViolation(__('Invalid scan id.'));
        }

        if (($existing = StockCountScan::query()->where('client_uuid', $clientUuid)->first()) !== null) {
            return $existing->resolved instanceof StockCountLine ? $existing->resolved : null;
        }

        return DB::transaction(function () use ($count, $clientUuid, $raw, $by, $quantity, $scannedAt, $device) {
            /** @var StockCount $count */
            $count = StockCount::query()->lockForUpdate()->findOrFail($count->id);

            if ($count->status !== StockCountStatus::InProgress) {
                throw new StockRuleViolation(__('Stock count :number is no longer open for scanning.', ['number' => $count->number]));
            }

            $result = app(ScanResolver::class)->resolve($raw, ScanContext::Count, $by, $count->branch_id);
            $line = null;
            $quantity = max(1, $quantity);

            if ($result->kind === ScanResultKind::Unit && $result->unit !== null) {
                $unit = $result->unit;
                $line = $count->lines()->where('stock_unit_id', $unit->id)->first();

                if ($line !== null) {
                    $line->update(['counted_qty' => 1]);
                } else {
                    $line = $count->lines()->create([
                        'product_id' => $unit->product_id,
                        'stock_unit_id' => $unit->id,
                        'expected_qty' => 0,
                        'counted_qty' => 1,
                        'result' => $unit->branch_id === $count->branch_id ? CountLineResult::ExtraUnknown : CountLineResult::ExtraOtherBranch,
                        'found_branch_id' => $unit->branch_id,
                        'note' => $unit->status !== StockStatus::InStock ? $unit->status->getLabel() : null,
                    ]);
                }
            } elseif ($result->product !== null && ! $result->product->isSerialTracked()) {
                $line = $count->lines()
                    ->where('product_id', $result->product->id)
                    ->whereNotNull('stock_batch_id')
                    ->whereHas('stockBatch', fn ($b) => $b
                        ->when($result->parsed->lot !== null, fn ($q) => $q->where('lot', $result->parsed->lot))
                        ->when($result->parsed->expiryDate !== null, fn ($q) => $q->where('expiry_date', $result->parsed->expiryDate)))
                    ->orderBy('id')
                    ->first();

                $line !== null
                    ? $line->increment('counted_qty', $quantity)
                    : $line = $count->lines()->create([
                        'product_id' => $result->product->id,
                        'expected_qty' => 0,
                        'counted_qty' => $quantity,
                        'result' => CountLineResult::ExtraUnknown,
                        'note' => trim(($result->parsed->lot ? __('Lot').' '.$result->parsed->lot : '').' '.($result->parsed->expiryDate ?? '')) ?: null,
                    ]);
            }

            $scan = new StockCountScan([
                'stock_count_id' => $count->id,
                'client_uuid' => $clientUuid,
                'raw_code' => mb_substr($raw, 0, 1000),
                'parsed' => $result->parsed->toArray(),
                'quantity' => $quantity,
                'scanned_by' => $by->id,
                'scanned_at' => $scannedAt ?? now(),
                'device_label' => $device !== null ? mb_substr($device, 0, 120) : null,
            ]);

            if ($line !== null) {
                $scan->resolved()->associate($line);
            }

            $scan->save();

            return $line?->refresh();
        });
    }

    /**
     * Type the counted quantity of a lot line.
     */
    public function setCounted(StockCountLine $line, int $counted, User $by): StockCountLine
    {
        $this->authorize($by, 'stock_counts.scan', $line->stockCount->branch_id);

        if ($line->stockCount->status !== StockCountStatus::InProgress) {
            throw new StockRuleViolation(__('Stock count :number is no longer open for scanning.', ['number' => $line->stockCount->number]));
        }

        $line->update(['counted_qty' => max(0, $counted)]);

        return $line;
    }

    public function submit(StockCount $count, User $by): StockCount
    {
        $this->authorize($by, 'stock_counts.scan', $count->branch_id);

        $count = DB::transaction(function () use ($count, $by) {
            /** @var StockCount $count */
            $count = StockCount::query()->with('lines')->lockForUpdate()->findOrFail($count->id);

            if ($count->status !== StockCountStatus::InProgress) {
                throw new StockRuleViolation(__('Stock count :number was already submitted.', ['number' => $count->number]));
            }

            foreach ($count->lines as $line) {
                if ($line->result !== null) {
                    continue; // extra lines already know their result
                }

                $result = match (true) {
                    $line->counted_qty === $line->expected_qty => CountLineResult::Found,
                    $line->stock_unit_id !== null && $line->counted_qty === 0 => CountLineResult::Missing,
                    default => CountLineResult::QtyMismatch,
                };

                $line->update([
                    'result' => $result,
                    'resolution' => $result === CountLineResult::Found ? CountLineResolution::Ignored : CountLineResolution::Pending,
                ]);
            }

            $count->forceFill(['status' => StockCountStatus::Submitted, 'submitted_by' => $by->id, 'submitted_at' => now()])->save();

            return $count;
        });

        $differences = $count->pendingDifferences();

        if ($differences > 0) {
            $this->issues->open(
                IssueType::CountDiscrepancy,
                IssueSeverity::Warning,
                $count->branch_id,
                $count,
                trans_choice('Stock count :number: :count difference to review|Stock count :number: :count differences to review', $differences, ['number' => $count->number]),
                ['count' => $count->number, 'differences' => $differences],
            );
        }

        $this->notifier->toBranch('stock_counts.review', $count->branch_id, new DocumentEvent(
            'stock_counts.submitted',
            Msg::key('Stock count :number is ready for review'),
            ['number' => $count->number],
            Msg::key(':count differences.'),
            ['count' => $differences],
            route('filament.admin.resources.stock-counts.view', $count),
            'heroicon-o-clipboard-document-check',
        ), $by);

        return $count;
    }

    /**
     * Decide one difference: adjust the books, write off, keep investigating, or ignore.
     */
    public function resolveLine(StockCountLine $line, CountLineResolution $resolution, User $by, ?string $note = null): StockCountLine
    {
        $count = $line->stockCount;
        $this->authorize($by, 'stock_counts.review', $count->branch_id);

        if (! in_array($count->status, [StockCountStatus::Submitted, StockCountStatus::Reviewed], true)) {
            throw new StockRuleViolation(__('Submit the count before reviewing it.'));
        }

        DB::transaction(function () use ($line, $resolution, $by, $note, $count) {
            /** @var StockCountLine $line */
            $line = StockCountLine::query()->with(['stockUnit', 'stockBatch'])->lockForUpdate()->findOrFail($line->id);
            $group = (string) Str::uuid();
            $reason = $note ?? __('Stock count :number', ['number' => $count->number]);
            $extra = in_array($line->result, [CountLineResult::ExtraOtherBranch, CountLineResult::ExtraUnknown], true);

            if ($resolution === CountLineResolution::Adjusted) {
                if ($extra) {
                    throw new StockRuleViolation(__('Items from another branch or not in the system cannot be adjusted here — move them with a transfer or receive them.'));
                }

                if ($line->stock_unit_id !== null) {
                    $this->stock->moveUnitOut($line->stockUnit, StockStatus::Lost, MovementType::CountAdjustment, $by, $count, $group, 'stock_counts.review', $reason);
                } elseif ($line->difference() < 0) {
                    $this->stock->removeFromBatch($line->stockBatch, -$line->difference(), MovementType::CountAdjustment, $by, $count, $group, 'stock_counts.review', $reason);
                } elseif ($line->difference() > 0) {
                    $this->stock->addToBatch($line->stockBatch, $line->difference(), MovementType::CountAdjustment, $by, $count, $group, 'stock_counts.review', $reason);
                }
            }

            if ($resolution === CountLineResolution::WrittenOff) {
                if ($extra || $line->difference() >= 0) {
                    throw new StockRuleViolation(__('Only missing items can be written off.'));
                }

                $this->writeOffs->report(
                    $count->branch,
                    WriteOffReason::Lost,
                    [$line->stock_unit_id !== null ? ['stock_unit_id' => $line->stock_unit_id] : ['stock_batch_id' => $line->stock_batch_id, 'quantity' => -$line->difference()]],
                    $by,
                    $reason,
                    source: $count,
                    permission: 'stock_counts.review',
                );
            }

            $line->forceFill(['resolution' => $resolution, 'resolved_by' => $by->id, 'resolved_at' => now(), 'note' => $note ?? $line->note])->save();

            if ($count->status === StockCountStatus::Submitted) {
                $count->forceFill(['status' => StockCountStatus::Reviewed, 'reviewed_by' => $by->id, 'reviewed_at' => now()])->save();
            }
        });

        return $line->refresh();
    }

    public function close(StockCount $count, User $by): StockCount
    {
        $this->authorize($by, 'stock_counts.review', $count->branch_id);

        if (! in_array($count->status, [StockCountStatus::Submitted, StockCountStatus::Reviewed], true)) {
            throw new StockRuleViolation(__('Submit the count before closing it.'));
        }

        if ($count->pendingDifferences() > 0) {
            throw new StockRuleViolation(__('Decide every difference before closing the count.'));
        }

        $count->forceFill(['status' => StockCountStatus::Closed, 'reviewed_by' => $by->id, 'reviewed_at' => now()])->save();
        $this->issues->resolveFor($count, IssueType::CountDiscrepancy, __('Count closed.'), $by);

        return $count;
    }

    public function cancel(StockCount $count, User $by): StockCount
    {
        $this->authorize($by, 'stock_counts.start', $count->branch_id);

        if ($count->status !== StockCountStatus::InProgress) {
            throw new StockRuleViolation(__('Only a count still in progress can be cancelled.'));
        }

        $count->forceFill(['status' => StockCountStatus::Cancelled])->save();

        return $count;
    }

    private function authorize(User $by, string $permission, Branch|int $branch): void
    {
        $branchId = $branch instanceof Branch ? $branch->id : $branch;

        if (! $this->scope->allows($by, $permission, $branchId)) {
            throw new AuthorizationException(__('You are not allowed to do this in branch :code.', ['code' => (string) Branch::query()->whereKey($branchId)->value('code')]));
        }
    }
}
