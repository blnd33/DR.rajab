<?php

namespace App\Services\Expiry;

use App\Enums\StockStatus;
use App\Models\ExpiryLevel;
use App\Models\StockBatch;
use App\Models\StockUnit;
use App\Notifications\ExpiryLevelEntered;
use App\Services\Stock\AlertRecipients;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Daily: units and lot buckets still in stock that entered a new expiry level get
 * last_expiry_level_id updated, and — for levels with "notify on enter" — one digest per
 * branch and level goes to users who can see stock there.
 */
class ExpiryTransitionNotifier
{
    public function __construct(
        private readonly ExpiryClassifier $expiry,
        private readonly AlertRecipients $recipients,
    ) {}

    /**
     * @return array<int, array<int, int>> level id → branch id → quantity that entered it
     */
    public function run(bool $notify = true): array
    {
        $entered = [];

        foreach ($this->expiry->levels() as $level) {
            [$from, $to] = $this->expiry->dateRange($level);

            $units = $this->inRange(StockUnit::query()->whereIn('status', StockStatus::onHand()), $from, $to, $level);
            $batches = $this->inRange(StockBatch::query()->where('quantity', '>', 0), $from, $to, $level);

            DB::transaction(function () use ($units, $batches, $level, &$entered) {
                foreach ((clone $units)->groupBy('branch_id')->selectRaw('branch_id, COUNT(*) AS qty')->pluck('qty', 'branch_id') as $branchId => $qty) {
                    $entered[$level->id][(int) $branchId] = ($entered[$level->id][(int) $branchId] ?? 0) + (int) $qty;
                }

                foreach ((clone $batches)->groupBy('branch_id')->selectRaw('branch_id, SUM(quantity) AS qty')->pluck('qty', 'branch_id') as $branchId => $qty) {
                    $entered[$level->id][(int) $branchId] = ($entered[$level->id][(int) $branchId] ?? 0) + (int) $qty;
                }

                // Metadata only (no stock change, so no ledger row and no updated_at bump).
                $units->toBase()->update(['last_expiry_level_id' => $level->id]);
                $batches->toBase()->update(['last_expiry_level_id' => $level->id]);
            });
        }

        if ($notify) {
            $this->notify($entered);
        }

        return $entered;
    }

    /**
     * @template TModel of StockUnit|StockBatch
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function inRange(Builder $query, ?string $from, ?string $to, ExpiryLevel $level): Builder
    {
        return $query
            ->whereNotNull('expiry_date')
            ->when($from !== null, fn ($q) => $q->where('expiry_date', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('expiry_date', '<=', $to))
            ->where(fn ($q) => $q->whereNull('last_expiry_level_id')->orWhere('last_expiry_level_id', '<>', $level->id));
    }

    /**
     * @param  array<int, array<int, int>>  $entered
     */
    private function notify(array $entered): void
    {
        $levels = $this->expiry->levels()->keyBy('id');

        foreach ($entered as $levelId => $branches) {
            if (! ($levels[$levelId]->notify_on_enter ?? false)) {
                continue;
            }

            foreach ($branches as $branchId => $quantity) {
                if ($quantity <= 0) {
                    continue;
                }

                Notification::send(
                    $this->recipients->for('stock.view', $branchId),
                    new ExpiryLevelEntered($branchId, $levelId, $quantity),
                );
            }
        }
    }
}
