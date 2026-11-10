<?php

namespace App\Services\Insight;

use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;
use App\Services\Reports\Report;
use App\Services\Reports\ReportContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Who asks, for which period (business-time days), which branch (null = all of theirs) and in
 * which display currency. Each fact limits itself further to the branches its permission allows.
 */
final class FactContext
{
    private function __construct(
        public readonly User $user,
        public readonly Carbon $from,
        public readonly Carbon $until,
        public readonly ?int $branch,
        public readonly string $currency,
    ) {}

    /**
     * @param  array<string, mixed>  $input  from, until (YYYY-MM-DD), branch (id), currency; default today
     */
    public static function make(User $user, array $input = []): self
    {
        $tz = config('orthotrack.timezone');
        $from = filled($input['from'] ?? null) ? Carbon::parse((string) $input['from'], $tz)->startOfDay() : now($tz)->startOfDay();
        $until = filled($input['until'] ?? null) ? Carbon::parse((string) $input['until'], $tz)->endOfDay() : $from->copy()->endOfDay();

        if ($until->lessThan($from)) {
            [$from, $until] = [$until->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        $branch = filled($input['branch'] ?? null) ? (int) $input['branch'] : null;
        $currency = in_array($input['currency'] ?? null, config('orthotrack.currencies'), true) ? (string) $input['currency'] : 'USD';

        return new self($user, $from, $until, $branch, $currency);
    }

    /**
     * One whole business day.
     */
    public static function day(User $user, CarbonInterface|string $date, ?int $branch = null, string $currency = 'USD'): self
    {
        $day = $date instanceof CarbonInterface ? $date->copy()->setTimezone(config('orthotrack.timezone'))->toDateString() : $date;

        return self::make($user, ['from' => $day, 'until' => $day, 'branch' => $branch, 'currency' => $currency]);
    }

    /**
     * The previous calendar day in business time.
     */
    public static function yesterday(User $user, ?int $branch = null, string $currency = 'USD'): self
    {
        return self::day($user, now(config('orthotrack.timezone'))->subDay(), $branch, $currency);
    }

    /**
     * Branches this user may see with $permission, narrowed to the chosen branch.
     *
     * @return list<int>|null null = every branch
     */
    public function branchIds(string $permission): ?array
    {
        $allowed = app(BranchScopeResolver::class)->branchIdsFor($this->user, $permission);

        return match (true) {
            $this->branch === null => $allowed,
            $allowed === null => [$this->branch],
            default => in_array($this->branch, $allowed, true) ? [$this->branch] : [],
        };
    }

    public function canSee(string $permission): bool
    {
        $ids = $this->branchIds($permission);

        return $ids === null || $ids !== [];
    }

    /**
     * Limit a query to the user's branches for $permission (a row matches on any of the columns).
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @param  string|list<string>  $columns
     * @return TBuilder
     */
    public function scope(Builder $query, string $permission, string|array $columns = 'branch_id'): Builder
    {
        $ids = $this->branchIds($permission);

        if ($ids === null) {
            return $query;
        }

        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($columns, $ids) {
            foreach ((array) $columns as $column) {
                $q->orWhereIn($column, $ids);
            }
        });
    }

    /**
     * The period for timestamp columns, which are stored in UTC.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function bounds(): array
    {
        return [$this->from->copy()->utc(), $this->until->copy()->utc()];
    }

    /**
     * The period for date columns (YYYY-MM-DD, business time).
     *
     * @return array{0: string, 1: string}
     */
    public function dates(): array
    {
        return [$this->from->toDateString(), $this->until->toDateString()];
    }

    public function isOneDay(): bool
    {
        return $this->from->toDateString() === $this->until->toDateString();
    }

    /**
     * A report run with this period, branch and currency (reports apply reports.view).
     */
    public function report(Report $report, ?string $group = null): ReportContext
    {
        return ReportContext::make($report, $this->user, array_filter([
            'from' => $this->from->toDateString(),
            'until' => $this->until->toDateString(),
            'branch' => $this->branch,
            'currency' => $this->currency,
            'group' => $group,
        ], fn ($v) => $v !== null));
    }

    /**
     * Same filters for another period (e.g. "today" next to the summary's "yesterday").
     *
     * @param  array<string, mixed>  $input
     */
    public function with(array $input): self
    {
        return self::make($this->user, array_merge([
            'from' => $this->from->toDateString(),
            'until' => $this->until->toDateString(),
            'branch' => $this->branch,
            'currency' => $this->currency,
        ], $input));
    }
}
