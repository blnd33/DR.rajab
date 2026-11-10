<?php

namespace App\Services\Insight;

use App\Models\User;

/**
 * Every fact, in summary order. Each person gets only the facts their permissions allow.
 */
final class FactRegistry
{
    /** @var list<class-string<Fact>> */
    public const FACTS = [
        // At a glance (a period)
        Facts\SurgeriesDone::class,
        Facts\KitsMoved::class,
        Facts\TransfersMoved::class,
        Facts\StockReceived::class,
        // Money
        Facts\Sales::class,
        Facts\PaymentsReceived::class,
        Facts\Profit::class,
        Facts\Expenses::class,
        Facts\HospitalBalances::class,
        Facts\SupplierInvoicesDue::class,
        Facts\CashInBoxes::class,
        // Kits
        Facts\KitsOut::class,
        Facts\UnaccountedImplants::class,
        // Expiry and stock
        Facts\Expiring::class,
        Facts\BelowMinimum::class,
        // Needs attention
        Facts\OpenIssues::class,
        Facts\ApprovalsWaiting::class,
        Facts\TransfersToReceive::class,
        Facts\DraftSurgeries::class,
    ];

    /**
     * @return list<Fact>
     */
    public function all(): array
    {
        return array_map(fn (string $class) => app($class), self::FACTS);
    }

    public function find(string $key): ?Fact
    {
        foreach (self::FACTS as $class) {
            if ($class::key() === $key) {
                return app($class);
            }
        }

        return null;
    }

    /**
     * @return list<Fact>
     */
    public function visibleTo(User $user): array
    {
        return array_values(array_filter($this->all(), fn (Fact $fact) => $fact->canSee($user)));
    }

    /**
     * The fact's answer, or null when the user may not see it.
     */
    public function build(Fact|string $fact, FactContext $context): ?FactResult
    {
        $fact = is_string($fact) ? $this->find($fact) : $fact;

        if ($fact === null || ! $fact->canSee($context->user)) {
            return null;
        }

        return $fact->build($context);
    }

    /**
     * Every fact this user may see, answered, grouped by section (summary order).
     *
     * @param  list<string>|null  $only  fact keys
     * @return array<string, list<FactResult>> section value → results
     */
    public function buildAll(FactContext $context, ?array $only = null): array
    {
        $sections = [];

        foreach ($this->visibleTo($context->user) as $fact) {
            if ($only !== null && ! in_array($fact::key(), $only, true)) {
                continue;
            }

            $sections[$fact->section()->value][] = $fact->build($context);
        }

        return $sections;
    }
}
