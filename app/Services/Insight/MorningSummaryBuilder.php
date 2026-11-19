<?php

namespace App\Services\Insight;

use App\Models\User;
use App\Support\Locale\Display;
use Illuminate\Support\Carbon;
use Illuminate\Support\Traits\Localizable;

/**
 * Builds one person's morning summary for one morning: the facts they may see, in the
 * sections they chose, for the day before (things that are "now" — kits out, expiry, waiting
 * work — as they stand when it is built). Empty facts are left out. Texts are in the person's
 * language; the numbers are kept too, for the short push line.
 */
class MorningSummaryBuilder
{
    use Localizable;

    public function __construct(private readonly FactRegistry $facts) {}

    /**
     * @param  string  $date  the morning (YYYY-MM-DD, business time)
     * @return array{date: string, day: string, branch_id: ?int, currency: string, locale: string, built_at: string, sections: array<string, list<array<string, mixed>>>}
     */
    public function build(User $user, string $date, ?SummarySettings $settings = null): array
    {
        $settings ??= SummarySettings::for($user);

        return $this->withLocale($user->preferredLocale() ?: app()->getLocale(), function () use ($user, $date, $settings) {
            $day = Carbon::parse($date, config('orthotrack.timezone'))->subDay()->toDateString();
            $context = FactContext::day($user, $day, $settings->branchId);
            $sections = [];

            foreach ($this->facts->visibleTo($user) as $fact) {
                if (! $settings->wants($fact->section())) {
                    continue;
                }

                $result = $fact->build($context);

                if (! $result->isEmpty()) {
                    $sections[$fact->section()->value][] = $result->toArray();
                }
            }

            return [
                'date' => $date,
                'day' => $day,
                'branch_id' => $settings->branchId,
                'currency' => $context->currency,
                'locale' => app()->getLocale(),
                'built_at' => now()->toIso8601String(),
                'sections' => $sections,
            ];
        });
    }

    /**
     * The one-line push text: counts and totals only — never a patient's name (it shows on a
     * locked phone).
     *
     * @param  array<string, mixed>  $payload
     */
    public static function headline(array $payload): string
    {
        $numbers = [];

        foreach ($payload['sections'] ?? [] as $results) {
            foreach ($results as $result) {
                $numbers[$result['key']] = $result['numbers'] ?? [];
            }
        }

        $currency = (string) ($payload['currency'] ?? 'USD');
        $count = fn (string $fact, string $number) => (int) ($numbers[$fact][$number] ?? 0);
        $parts = [];

        if (($n = $count('surgeries-done', 'surgeries')) > 0) {
            $parts[] = trans_choice(':count surgery|:count surgeries', $n, ['count' => Display::number($n)]);
        }

        if ((float) ($numbers['sales']['total'] ?? 0) > 0) {
            $parts[] = __(':amount in sales', ['amount' => Display::money((string) $numbers['sales']['total'], $currency)]);
        }

        if (($n = $count('kits-out', 'overdue')) > 0) {
            $parts[] = trans_choice(':count instrument set overdue|:count instrument sets overdue', $n, ['count' => Display::number($n)]);
        }

        if (($n = $count('expiring', 'expired')) > 0) {
            $parts[] = trans_choice(':count item expired|:count items expired', $n, ['count' => Display::number($n)]);
        }

        if (($n = $count('below-minimum', 'products')) > 0) {
            $parts[] = trans_choice(':count product below minimum|:count products below minimum', $n, ['count' => Display::number($n)]);
        }

        if (($n = $count('open-issues', 'issues')) > 0) {
            $parts[] = trans_choice(':count open issue|:count open issues', $n, ['count' => Display::number($n)]);
        }

        if (($n = $count('approvals-waiting', 'approvals')) > 0) {
            $parts[] = trans_choice(':count approval waiting|:count approvals waiting', $n, ['count' => Display::number($n)]);
        }

        return $parts === []
            ? __('Yesterday was quiet and nothing is waiting.')
            : __('Yesterday: :list', ['list' => implode(' · ', $parts)]);
    }
}
