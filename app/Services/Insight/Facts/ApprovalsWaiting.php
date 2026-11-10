<?php

namespace App\Services\Insight\Facts;

use App\Enums\ApprovalType;
use App\Models\Approval;
use App\Models\User;
use App\Services\Insight\Fact;
use App\Services\Insight\FactContext;
use App\Services\Insight\FactResult;
use App\Services\Insight\FactSection;
use App\Services\Money\ApprovalService;
use App\Services\Permissions\BranchScopeResolver;

/**
 * Payments and expenses waiting for this person's approval.
 */
class ApprovalsWaiting extends Fact
{
    public function __construct(private readonly ApprovalService $approvals) {}

    public static function key(): string
    {
        return 'approvals-waiting';
    }

    public function title(): string
    {
        return __('Waiting for your approval');
    }

    public function section(): FactSection
    {
        return FactSection::Attention;
    }

    protected function permission(): string
    {
        return ApprovalService::permission(ApprovalType::Expense);
    }

    public function canSee(User $user): bool
    {
        foreach (ApprovalType::cases() as $type) {
            if (app(BranchScopeResolver::class)->hasAny($user, ApprovalService::permission($type))) {
                return true;
            }
        }

        return false;
    }

    public function build(FactContext $context): FactResult
    {
        $query = $this->approvals->waitingFor($context->user);

        if ($context->branch !== null) {
            $query->where('branch_id', $context->branch);
        }

        $total = (clone $query)->count();
        $lines = $query->oldest('requested_at')->limit(self::MAX_LINES)->get()
            ->map(fn (Approval $approval) => self::line($approval->type->getLabel(), self::money((string) $approval->amount, $approval->currency)))->all();

        return $this->result($context, self::count($total), $lines, ['approvals' => $total],
            'filament.admin.resources.approvals.index',
            tone: $total > 0 ? FactResult::WARNING : FactResult::GOOD);
    }
}
