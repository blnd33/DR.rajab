<?php

namespace App\Services\Movement;

use App\Enums\LocationType;
use App\Enums\RequestPriority;
use App\Enums\StockRequestStatus;
use App\Enums\StockStatus;
use App\Exceptions\Domain\StockRuleViolation;
use App\Models\Branch;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockRequest;
use App\Models\StockUnit;
use App\Models\User;
use App\Notifications\DocumentEvent;
use App\Services\Expiry\ExpiryClassifier;
use App\Services\Numbering\DocumentNumberGenerator;
use App\Services\Permissions\BranchScopeResolver;
use App\Support\Msg;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Stock requests (Module 9): a branch asks → the approver edits quantities and picks the
 * source branch per line (suggested: most valid stock) → one draft transfer per source.
 */
class StockRequestService
{
    public function __construct(
        private readonly BranchScopeResolver $scope,
        private readonly DocumentNumberGenerator $numbers,
        private readonly TransferService $transfers,
        private readonly Notifier $notifier,
        private readonly ExpiryClassifier $expiry,
    ) {}

    /**
     * @param  list<array{product_id: int, quantity: int, note?: ?string}>  $lines
     */
    public function create(Branch $branch, array $lines, User $by, ?Branch $preferredSource = null, RequestPriority $priority = RequestPriority::Normal, ?string $neededBy = null, ?string $notes = null): StockRequest
    {
        $this->authorize($by, 'stock_requests.create', $branch);

        $lines = array_values(array_filter($lines, fn ($l) => (int) ($l['quantity'] ?? 0) > 0));

        if ($lines === []) {
            throw new StockRuleViolation(__('Add at least one product with a quantity.'));
        }

        if ($preferredSource?->id === $branch->id) {
            throw new StockRuleViolation(__('A station cannot request from itself.'), 'preferred_source_branch_id');
        }

        $request = DB::transaction(function () use ($branch, $lines, $by, $preferredSource, $priority, $neededBy, $notes) {
            $request = StockRequest::query()->create([
                'number' => $this->numbers->next('REQ', $branch),
                'requesting_branch_id' => $branch->id,
                'preferred_source_branch_id' => $preferredSource?->id,
                'status' => StockRequestStatus::Pending,
                'priority' => $priority,
                'needed_by' => $neededBy,
                'notes' => $notes,
                'requested_by' => $by->id,
            ]);

            $seen = [];

            foreach ($lines as $line) {
                $productId = (int) $line['product_id'];

                if (isset($seen[$productId]) || ! Product::query()->whereKey($productId)->exists()) {
                    throw new StockRuleViolation(__('Each product once, please.'));
                }

                $seen[$productId] = true;
                $request->items()->create([
                    'product_id' => $productId,
                    'quantity_requested' => (int) $line['quantity'],
                    'source_branch_id' => $preferredSource->id ?? $this->suggestSource(Product::query()->findOrFail($productId), (int) $line['quantity'], $branch->id),
                    'note' => $line['note'] ?? null,
                ]);
            }

            return $request;
        });

        $this->notifier->toBranch('stock_requests.approve', $branch->id, new DocumentEvent(
            'stock_requests.created',
            $priority === RequestPriority::Urgent ? Msg::key('Urgent stock request :number from :branch') : Msg::key('Stock request :number from :branch'),
            ['number' => $request->number, 'branch' => $branch->code],
            Msg::key(':count products requested.'),
            ['count' => count($lines)],
            route('ops.requests.show', $request),
            'heroicon-o-inbox-arrow-down',
        ), $by);

        return $request;
    }

    /**
     * Branch with the most valid (not expired / not blocked) stock on the shelf, other than
     * the requesting one. Null when nobody has any.
     */
    public function suggestSource(Product $product, int $quantity, int $exceptBranchId): ?int
    {
        $blockedThrough = $this->expiry->blockedThrough()->toDateString();
        $valid = fn ($q) => $q->where(fn ($w) => $w->whereNull('expiry_date')->orWhere('expiry_date', '>', $blockedThrough));

        $counts = $product->isSerialTracked()
            ? $valid(StockUnit::query()->where('product_id', $product->id)->where('status', StockStatus::InStock))
                ->where('branch_id', '<>', $exceptBranchId)
                ->groupBy('branch_id')->selectRaw('branch_id, COUNT(*) AS qty')->pluck('qty', 'branch_id')
            : $valid(StockBatch::query()->where('product_id', $product->id)->where('location_type', LocationType::Shelf)->where('quantity', '>', 0))
                ->where('branch_id', '<>', $exceptBranchId)
                ->groupBy('branch_id')->selectRaw('branch_id, SUM(quantity) AS qty')->pluck('qty', 'branch_id');

        $open = Branch::query()->active()->pluck('id')->all();
        $counts = $counts->filter(fn ($qty, $branchId) => in_array((int) $branchId, $open, true))->sortDesc();

        return $counts->isEmpty() ? null : (int) $counts->keys()->first();
    }

    /**
     * @param  array<int, array{quantity_approved: int, source_branch_id: ?int}>  $decisions  item id → decision
     */
    public function approve(StockRequest $request, array $decisions, User $by, ?string $note = null): StockRequest
    {
        $this->authorize($by, 'stock_requests.approve', $request->requesting_branch_id);

        $request = DB::transaction(function () use ($request, $decisions, $by, $note) {
            /** @var StockRequest $request */
            $request = StockRequest::query()->with(['items', 'requestingBranch'])->lockForUpdate()->findOrFail($request->id);

            if ($request->status !== StockRequestStatus::Pending) {
                throw new StockRuleViolation(__('This request was already decided.'));
            }

            $partial = false;
            $bySource = [];

            foreach ($request->items as $item) {
                $decision = $decisions[$item->id] ?? ['quantity_approved' => $item->quantity_requested, 'source_branch_id' => $item->source_branch_id];
                $approved = max(0, (int) $decision['quantity_approved']);
                $source = $decision['source_branch_id'] ?? null;

                if ($approved > 0 && blank($source)) {
                    throw new StockRuleViolation(__('Choose the sending station for :sku.', ['sku' => $item->product->sku]));
                }

                if ($approved > 0 && (int) $source === $request->requesting_branch_id) {
                    throw new StockRuleViolation(__('A station cannot request from itself.'));
                }

                $partial = $partial || $approved < $item->quantity_requested;
                $item->update(['quantity_approved' => $approved, 'source_branch_id' => $approved > 0 ? (int) $source : null]);

                if ($approved > 0) {
                    $bySource[(int) $source][] = $item;
                }
            }

            if ($bySource === []) {
                throw new StockRuleViolation(__('Approve at least one line, or reject the request.'));
            }

            foreach ($bySource as $sourceId => $items) {
                $transfer = $this->transfers->createDraft(Branch::query()->findOrFail($sourceId), $request->requestingBranch, $by, __('For stock request :number', ['number' => $request->number]), $request);

                foreach ($items as $item) {
                    $item->update(['transfer_id' => $transfer->id]);
                }
            }

            $request->forceFill([
                'status' => $partial ? StockRequestStatus::PartiallyApproved : StockRequestStatus::Approved,
                'reviewed_by' => $by->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();

            return $request;
        });

        $this->notifier->toUser($request->requester, new DocumentEvent(
            'stock_requests.decided',
            Msg::key('Stock request :number was approved'),
            ['number' => $request->number],
            null,
            [],
            route('ops.requests.show', $request),
            'heroicon-o-check-circle',
        ));

        foreach ($request->transfers as $transfer) {
            $this->notifier->toBranch('transfers.send', $transfer->from_branch_id, new DocumentEvent(
                'transfers.to_prepare',
                Msg::key('Prepare transfer :number for :to'),
                ['number' => $transfer->number, 'to' => $request->requestingBranch->code],
                Msg::key('Scan the requested items and send them.'),
                [],
                route('ops.transfers.show', $transfer),
                'heroicon-o-truck',
            ), $by);
        }

        return $request;
    }

    public function reject(StockRequest $request, string $note, User $by): StockRequest
    {
        $this->authorize($by, 'stock_requests.approve', $request->requesting_branch_id);

        if (blank($note)) {
            throw new StockRuleViolation(__('A reason is required.'), 'note');
        }

        if ($request->status !== StockRequestStatus::Pending) {
            throw new StockRuleViolation(__('This request was already decided.'));
        }

        $request->forceFill([
            'status' => StockRequestStatus::Rejected,
            'reviewed_by' => $by->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ])->save();

        $this->notifier->toUser($request->requester, new DocumentEvent(
            'stock_requests.decided',
            Msg::key('Stock request :number was rejected'),
            ['number' => $request->number],
            Msg::key('Reason: :reason'),
            ['reason' => $note],
            route('ops.requests.show', $request),
            'heroicon-o-x-circle',
        ));

        return $request;
    }

    public function cancel(StockRequest $request, User $by): StockRequest
    {
        if ($request->requested_by !== $by->id && ! $this->scope->allows($by, 'stock_requests.approve', $request->requesting_branch_id)) {
            throw new AuthorizationException(__('Only the requester can cancel this request.'));
        }

        if ($request->status !== StockRequestStatus::Pending) {
            throw new StockRuleViolation(__('This request was already decided.'));
        }

        $request->forceFill(['status' => StockRequestStatus::Cancelled])->save();

        return $request;
    }

    public function isOverdue(StockRequest $request): bool
    {
        return $request->needed_by !== null && $request->status === StockRequestStatus::Pending && $request->needed_by->lt(Carbon::today());
    }

    private function authorize(User $by, string $permission, Branch|int $branch): void
    {
        $branchId = $branch instanceof Branch ? $branch->id : $branch;

        if (! $this->scope->allows($by, $permission, $branchId)) {
            throw new AuthorizationException(__('You are not allowed to do this in station :code.', ['code' => (string) Branch::query()->whereKey($branchId)->value('code')]));
        }
    }
}
