<?php

namespace App\Services\Money;

use App\Models\User;

/**
 * A document that can wait in the approvals queue: the queue hands the decision back here,
 * and the document's own service does the work (post the payment, the expense…).
 */
interface ApprovalHandler
{
    public function approvalGranted(User $by): void;

    public function approvalRejected(User $by, string $reason): void;
}
