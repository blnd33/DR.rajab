<?php

namespace App\Services\Stock;

use App\Models\LowStock;
use App\Models\User;
use App\Notifications\LowStockDigest;
use App\Services\Permissions\BranchScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Products below their minimum per branch (Module 17), from the v_low_stock view.
 * The daily digest goes to people who can request stock in that branch. The one-click
 * "Request from another branch" button arrives with stock requests in Phase 2.
 */
class LowStockDetector
{
    public function __construct(
        private readonly BranchScopeResolver $scope,
        private readonly AlertRecipients $recipients,
    ) {}

    /**
     * @return Builder<LowStock>
     */
    public function query(?User $user = null): Builder
    {
        $query = LowStock::query()->with(['product', 'branch']);

        return $user === null ? $query : $this->scope->apply($query, $user, 'stock.view');
    }

    /**
     * @return Collection<int|string, EloquentCollection<int, LowStock>> branch id → rows
     */
    public function byBranch(): Collection
    {
        return $this->query()->orderByDesc('shortage')->get()->groupBy('branch_id');
    }

    public function notify(): int
    {
        $sent = 0;

        foreach ($this->byBranch() as $branchId => $rows) {
            $recipients = $this->recipients->for('stock_requests.create', (int) $branchId);

            if ($recipients->isEmpty()) {
                continue;
            }

            Notification::send($recipients, new LowStockDigest(
                (int) $branchId,
                $rows->count(),
                $rows->map(fn (LowStock $row) => $row->product->sku)->values()->all(),
            ));

            $sent++;
        }

        return $sent;
    }
}
