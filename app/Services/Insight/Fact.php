<?php

namespace App\Services\Insight;

use App\Enums\Panel as AppPanel;
use App\Models\User;
use App\Services\Permissions\BranchScopeResolver;
use App\Support\Locale\Display;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Route;

/**
 * One fact: answers one thing for one user, period and branch — "sales by hospital",
 * "kits out / overdue", "what expires soon". Shared by the morning summary and Quick answers.
 *
 * A fact checks its own permission and branch scope and reuses the existing queries (reports,
 * services); it never writes. Adding a question = one more Fact class in FactRegistry::FACTS.
 */
abstract class Fact
{
    /** Lines in a result (the full list is one tap away). */
    public const MAX_LINES = 5;

    abstract public static function key(): string;

    abstract public function title(): string;

    abstract public function section(): FactSection;

    /**
     * The permission that opens this fact (in at least one branch).
     */
    abstract protected function permission(): string;

    abstract public function build(FactContext $context): FactResult;

    /**
     * True when the fact is about a period ("yesterday", "this month"); false when it shows how
     * things stand now (kits out, cash in the boxes).
     */
    public function usesPeriod(): bool
    {
        return false;
    }

    public function canSee(User $user): bool
    {
        return app(BranchScopeResolver::class)->hasAny($user, $this->permission());
    }

    /**
     * @param  list<array{label: string, value: string, url?: ?string, code?: bool}>  $lines
     * @param  array<string, int|float|string>  $numbers
     * @param  array<string, mixed>  $parameters
     */
    protected function result(
        FactContext $context,
        string $headline,
        array $lines = [],
        array $numbers = [],
        ?string $route = null,
        array $parameters = [],
        string $tone = FactResult::NEUTRAL,
        ?string $note = null,
    ): FactResult {
        return new FactResult(static::key(), $this->title(), $headline, array_slice($lines, 0, self::MAX_LINES), $numbers,
            $this->link($context->user, $route, $parameters), $tone, $note);
    }

    /**
     * A link into the admin panel, for people who may open it.
     *
     * @param  array<string, mixed>  $parameters
     */
    protected function link(User $user, ?string $route, array $parameters = []): ?string
    {
        if ($route === null || ! Route::has($route) || ! $user->canAccessAppPanel(AppPanel::Admin)) {
            return null;
        }

        return route($route, $parameters);
    }

    protected static function count(int|float $value): string
    {
        return Display::number($value);
    }

    protected static function money(BigDecimal|string $amount, string $currency): string
    {
        return Display::money((string) $amount, $currency);
    }

    /**
     * A money amount for the numbers (2 decimals, plain digits).
     */
    protected static function amount(BigDecimal $amount): string
    {
        return (string) $amount->toScale(2, RoundingMode::HalfUp);
    }

    /**
     * @return array{label: string, value: string, url?: ?string, code?: bool}
     */
    protected static function line(string $label, string $value, ?string $url = null, bool $code = false): array
    {
        return array_filter(['label' => $label, 'value' => $value, 'url' => $url, 'code' => $code ?: null], fn ($v) => $v !== null);
    }
}
