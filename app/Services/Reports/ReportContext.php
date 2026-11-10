<?php

namespace App\Services\Reports;

use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;
use Illuminate\Support\Carbon;

/**
 * The filters a report runs with, already limited to the user's branches (P5-1).
 * Built from the same flat array on the screen, the Excel export and the print page.
 */
final class ReportContext
{
    public bool $missingRates = false;

    /**
     * @param  list<int>|null  $branchIds  null = every branch
     */
    public function __construct(
        public readonly User $user,
        public readonly Carbon $from,
        public readonly Carbon $until,
        public readonly ?array $branchIds,
        public readonly string $currency,
        public readonly ?string $group,
    ) {}

    /**
     * @param  array{from?: ?string, until?: ?string, branch?: int|string|null, currency?: ?string, group?: ?string}  $input
     */
    public static function make(Report $report, User $user, array $input): self
    {
        $tz = config('orthotrack.timezone');
        $from = filled($input['from'] ?? null) ? Carbon::parse($input['from'], $tz)->startOfDay() : $report->defaultFrom();
        $until = filled($input['until'] ?? null) ? Carbon::parse($input['until'], $tz)->endOfDay() : now($tz)->endOfDay();

        if ($until->lessThan($from)) {
            [$from, $until] = [$until->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        $allowed = app(BranchScopeResolver::class)->branchIdsFor($user, 'reports.view');
        $chosen = filled($input['branch'] ?? null) ? [(int) $input['branch']] : null;
        $branchIds = match (true) {
            $chosen === null => $allowed,
            $allowed === null => $chosen,
            default => array_values(array_intersect($allowed, $chosen)) ?: [0],
        };

        $groups = array_keys($report->groups());
        $group = in_array($input['group'] ?? null, $groups, true) ? (string) $input['group'] : ($groups[0] ?? null);
        $currency = in_array($input['currency'] ?? null, ['USD', 'IQD', 'EUR'], true) ? (string) $input['currency'] : 'USD';

        return new self($user, $from, $until, $branchIds, $currency, $group);
    }

    /**
     * The period for comparing with timestamp columns, which are stored in UTC. ($from and
     * $until are business-time days; sent as they are, a day would run 03:00 → 03:00.)
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function bounds(): array
    {
        return [$this->from->copy()->utc(), $this->until->copy()->utc()];
    }

    public function canSeeProfit(): bool
    {
        return $this->user->can('reports.profit');
    }

    /**
     * The flat filter array (for links to the print page and the export).
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter([
            'from' => $this->from->toDateString(),
            'until' => $this->until->toDateString(),
            'branch' => $this->branchIds !== null && count($this->branchIds) === 1 ? (string) $this->branchIds[0] : null,
            'currency' => $this->currency,
            'group' => $this->group,
        ], fn ($v) => $v !== null);
    }
}
