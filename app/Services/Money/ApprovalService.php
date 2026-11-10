<?php

namespace App\Services\Money;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Exceptions\Domain\MoneyRuleViolation;
use App\Models\Approval;
use App\Models\User;
use App\Notifications\DocumentEvent;
use App\Services\Movement\Notifier;
use App\Services\Permissions\BranchScopeResolver;
use App\Services\Settings\SettingsRepository;
use App\Support\Locale\Display;
use App\Support\Msg;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * One approvals queue (P4-11). Documents above their threshold wait here; approving or
 * rejecting hands the decision back to the document's service (an ApprovalHandler).
 */
class ApprovalService
{
    public function __construct(
        private readonly BranchScopeResolver $scope,
        private readonly SettingsRepository $settings,
        private readonly Notifier $notifier,
    ) {}

    public static function permission(ApprovalType $type): string
    {
        return match ($type) {
            ApprovalType::SupplierPayment => 'supplier_payments.approve',
            ApprovalType::Expense => 'expenses.approve',
            ApprovalType::CommissionPayout => 'commissions.manage',
        };
    }

    /**
     * Approvals the user may see: their own requests, and every type they may decide in the
     * branches where they may decide it.
     *
     * @return Builder<Approval>
     */
    public function visibleTo(User $user): Builder
    {
        return $this->limitToVisible(Approval::query(), $user);
    }

    /**
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query  an approvals query
     * @return TBuilder
     */
    public function limitToVisible(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user) {
            $q->where('requested_by', $user->id);

            foreach (ApprovalType::cases() as $type) {
                $permission = self::permission($type);

                if (! $this->scope->hasAny($user, $permission)) {
                    continue;
                }

                $ids = $this->scope->branchIdsFor($user, $permission);
                $q->orWhere(function (Builder $t) use ($type, $ids) {
                    $t->where('type', $type);

                    if ($ids !== null) {
                        $t->whereIn('branch_id', $ids === [] ? [0] : $ids);
                    }
                });
            }
        });
    }

    /**
     * Pending approvals someone else asked for that this user may decide.
     *
     * @return Builder<Approval>
     */
    public function waitingFor(User $user): Builder
    {
        return $this->visibleTo($user)->where('status', ApprovalStatus::Pending)->where('requested_by', '<>', $user->id);
    }

    /**
     * Is an amount (in USD) above the configured threshold for this type?
     */
    public function needed(ApprovalType $type, string $amountUsd): bool
    {
        $key = match ($type) {
            ApprovalType::SupplierPayment => 'approvals.supplier_payment_threshold_usd',
            ApprovalType::Expense => 'approvals.expense_threshold_usd',
            ApprovalType::CommissionPayout => null,
        };

        if ($key === null) {
            return true;
        }

        $threshold = (string) ($this->settings->get($key) ?? config("orthotrack.settings.{$key}", 0));

        return BigDecimal::of($amountUsd)->abs()->isGreaterThan($threshold === '' ? '0' : $threshold);
    }

    public function request(Model $approvable, ApprovalType $type, ?int $branchId, string $amount, string $currency, string $amountUsd, User $by, string $title): Approval
    {
        $approval = new Approval([
            'type' => $type,
            'branch_id' => $branchId,
            'amount' => $amount,
            'currency' => $currency,
            'amount_usd' => $amountUsd,
            'status' => ApprovalStatus::Pending,
            'requested_by' => $by->id,
            'requested_at' => now(),
        ]);
        $approval->approvable()->associate($approvable);
        $approval->save();

        $event = new DocumentEvent(
            'approvals.requested',
            Msg::key('Waiting for your approval: :what'),
            ['what' => $title],
            Msg::key(':amount, asked by :user.'),
            ['amount' => Display::money($amount, $currency), 'user' => $by->name],
            route('filament.admin.resources.approvals.index'),
            'heroicon-o-hand-raised',
        );

        // Head-office documents (no branch) reach the approvers with a global scope.
        $this->notifier->toBranch(self::permission($type), $branchId ?? 0, $event, $by);

        return $approval;
    }

    public function approve(Approval $approval, User $by, ?string $note = null): Approval
    {
        return $this->decide($approval, ApprovalStatus::Approved, $by, $note);
    }

    public function reject(Approval $approval, User $by, string $note): Approval
    {
        if (blank($note)) {
            throw new MoneyRuleViolation(__('A reason is required.'), 'note');
        }

        return $this->decide($approval, ApprovalStatus::Rejected, $by, $note);
    }

    private function decide(Approval $approval, ApprovalStatus $status, User $by, ?string $note): Approval
    {
        if (! $this->scope->allows($by, self::permission($approval->type), $approval->branch_id)) {
            throw new AuthorizationException(__('You are not allowed to decide this.'));
        }

        return DB::transaction(function () use ($approval, $status, $by, $note) {
            /** @var Approval $approval */
            $approval = Approval::query()->lockForUpdate()->findOrFail($approval->id);

            if ($approval->status !== ApprovalStatus::Pending) {
                throw new MoneyRuleViolation(__('This was already decided.'));
            }

            if ($approval->requested_by === $by->id && ! $by->isSuperAdmin()) {
                throw new MoneyRuleViolation(__('Someone else must approve what you asked for.'));
            }

            $approval->forceFill(['status' => $status, 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => $note])->save();

            $approvable = $approval->approvable;

            if ($approvable instanceof ApprovalHandler) {
                $status === ApprovalStatus::Approved
                    ? $approvable->approvalGranted($by)
                    : $approvable->approvalRejected($by, (string) $note);
            }

            return $approval;
        });
    }
}
