<?php

namespace App\Services\Graphs;

use App\Models\User;
use App\Services\Reports\Report;
use App\Services\Reports\ReportContext;
use App\Services\Reports\SalesProfitReport;
use Illuminate\Support\Carbon;

/**
 * The graph filters: period, branch (within the user's report scope) and display currency.
 */
final class GraphContext
{
    /**
     * @param  ?list<int>  $branchIds  null = every branch
     */
    private function __construct(
        public readonly User $user,
        public readonly Carbon $from,
        public readonly Carbon $until,
        public readonly ?int $branch,
        public readonly ?array $branchIds,
        public readonly string $currency,
    ) {}

    /**
     * @param  array<string, mixed>  $input  from, until (YYYY-MM-DD), branch (id), currency
     */
    public static function make(User $user, array $input = []): self
    {
        $tz = config('orthotrack.timezone');
        $from = filled($input['from'] ?? null) ? Carbon::parse((string) $input['from'], $tz)->startOfDay() : self::defaultFrom();
        $until = filled($input['until'] ?? null) ? Carbon::parse((string) $input['until'], $tz)->endOfDay() : now($tz)->endOfDay();

        if ($until->lessThan($from)) {
            [$from, $until] = [$until->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        $branch = filled($input['branch'] ?? null) ? (int) $input['branch'] : null;
        $currency = in_array($input['currency'] ?? null, ['USD', 'IQD', 'EUR'], true) ? (string) $input['currency'] : 'USD';

        // Same branch rule as the reports.
        $scope = ReportContext::make(app(SalesProfitReport::class), $user, ['branch' => $branch, 'from' => $from->toDateString(), 'until' => $until->toDateString()]);

        return new self($user, $from, $until, $branch, $scope->branchIds, $currency);
    }

    /**
     * Twelve months, this one included.
     */
    public static function defaultFrom(): Carbon
    {
        return now(config('orthotrack.timezone'))->startOfMonth()->subMonthsNoOverflow(11);
    }

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
     * Every month of the period as Y-m, oldest first.
     *
     * @return list<string>
     */
    public function months(): array
    {
        $months = [];
        $cursor = $this->from->copy()->startOfMonth();

        while ($cursor->lessThanOrEqualTo($this->until) && count($months) < 120) {
            $months[] = $cursor->format('Y-m');
            $cursor->addMonthNoOverflow();
        }

        return $months;
    }

    /**
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter([
            'from' => $this->from->toDateString(),
            'until' => $this->until->toDateString(),
            'branch' => $this->branch !== null ? (string) $this->branch : null,
            'currency' => $this->currency,
        ], fn ($v) => $v !== null);
    }
}
