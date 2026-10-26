<?php

namespace App\Services\Money;

use App\Enums\RateSource;
use App\Enums\RateStatus;
use App\Exceptions\Domain\MoneyRuleViolation;
use App\Models\ExchangeRate;
use App\Models\FxSnapshot;
use App\Models\User;
use App\Services\Settings\SettingsRepository;
use App\Support\Money\FxConverter;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Exchange rates (P4-2): USD/IQD set by hand, EUR/USD by hand or as an ECB suggestion.
 * Confirming a rate creates a new immutable snapshot, the "current" one from then on.
 */
class FxService
{
    /** pair => permission */
    public const PAIRS = [
        'USD/IQD' => 'exchange_rates.set_usd_iqd',
        'EUR/USD' => 'exchange_rates.set_eur_usd',
    ];

    public function __construct(private readonly SettingsRepository $settings) {}

    public function current(): ?FxSnapshot
    {
        return FxSnapshot::query()->latest('id')->first();
    }

    public function currentOrFail(): FxSnapshot
    {
        return $this->current() ?? throw new MoneyRuleViolation(__('Set the exchange rates (USD/IQD and EUR/USD) first.'));
    }

    public function converter(?FxSnapshot $snapshot = null): FxConverter
    {
        return FxConverter::with($snapshot ?? $this->currentOrFail());
    }

    /**
     * Set a rate by hand: confirmed at once (a new snapshot when both pairs are known).
     */
    public function setRate(string $base, string $quote, string $rate, User $by, ?string $note = null): ExchangeRate
    {
        $pair = "{$base}/{$quote}";
        $this->authorize($by, $pair);

        return DB::transaction(function () use ($base, $quote, $rate, $by, $note) {
            $entry = ExchangeRate::query()->create([
                'base_currency' => $base,
                'quote_currency' => $quote,
                'rate' => $this->validRate($rate),
                'source' => RateSource::Manual,
                'status' => RateStatus::Confirmed,
                'effective_at' => now(),
                'set_by' => $by->id,
                'confirmed_by' => $by->id,
                'confirmed_at' => now(),
                'note' => $note,
            ]);

            $this->snapshot();

            return $entry;
        });
    }

    /**
     * A rate from an outside source: a suggestion until someone confirms it (or the setting
     * confirms EUR/USD automatically).
     */
    public function suggest(string $base, string $quote, string $rate, RateSource $source, ?string $note = null): ExchangeRate
    {
        $autoConfirm = "{$base}/{$quote}" === 'EUR/USD' && $this->settings->bool('fx.eur_usd_auto_confirm');

        return DB::transaction(function () use ($base, $quote, $rate, $source, $note, $autoConfirm) {
            $entry = ExchangeRate::query()->create([
                'base_currency' => $base,
                'quote_currency' => $quote,
                'rate' => $this->validRate($rate),
                'source' => $source,
                'status' => $autoConfirm ? RateStatus::Confirmed : RateStatus::Suggested,
                'effective_at' => now(),
                'confirmed_at' => $autoConfirm ? now() : null,
                'note' => $note,
            ]);

            if ($autoConfirm) {
                $this->snapshot();
            }

            return $entry;
        });
    }

    public function confirm(ExchangeRate $entry, User $by): ExchangeRate
    {
        $this->authorize($by, "{$entry->base_currency}/{$entry->quote_currency}");

        if ($entry->status !== RateStatus::Suggested) {
            throw new MoneyRuleViolation(__('This rate was already decided.'));
        }

        return DB::transaction(function () use ($entry, $by) {
            $entry->forceFill(['status' => RateStatus::Confirmed, 'confirmed_by' => $by->id, 'confirmed_at' => now(), 'effective_at' => now()])->save();
            $this->snapshot();

            return $entry;
        });
    }

    public function reject(ExchangeRate $entry, User $by): ExchangeRate
    {
        $this->authorize($by, "{$entry->base_currency}/{$entry->quote_currency}");

        if ($entry->status !== RateStatus::Suggested) {
            throw new MoneyRuleViolation(__('This rate was already decided.'));
        }

        $entry->forceFill(['status' => RateStatus::Rejected, 'confirmed_by' => $by->id, 'confirmed_at' => now()])->save();

        return $entry;
    }

    /**
     * Daily ECB reference rate for EUR/USD (a suggestion). Returns null when the fetch fails.
     */
    public function fetchEcb(): ?ExchangeRate
    {
        try {
            $xml = Http::timeout(10)->get('https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml')->throw()->body();
        } catch (Throwable) {
            return null;
        }

        if (preg_match("/currency=['\"]USD['\"]\\s+rate=['\"]([0-9.]+)['\"]/", $xml, $m) !== 1) {
            return null;
        }

        $latest = $this->latestConfirmed('EUR', 'USD');

        if ($latest !== null && BigDecimal::of($latest->rate)->isEqualTo($m[1])) {
            return null; // nothing new
        }

        return $this->suggest('EUR', 'USD', $m[1], RateSource::Ecb, __('ECB reference rate'));
    }

    public function latestConfirmed(string $base, string $quote): ?ExchangeRate
    {
        return ExchangeRate::query()
            ->where('base_currency', $base)->where('quote_currency', $quote)
            ->where('status', RateStatus::Confirmed)
            ->latest('effective_at')->latest('id')
            ->first();
    }

    /**
     * A new snapshot from the latest confirmed rate of each pair (only when both exist).
     */
    private function snapshot(): ?FxSnapshot
    {
        $usdIqd = $this->latestConfirmed('USD', 'IQD');
        $eurUsd = $this->latestConfirmed('EUR', 'USD');

        if ($usdIqd === null || $eurUsd === null) {
            return null;
        }

        return FxSnapshot::query()->create([
            'usd_iqd' => $usdIqd->rate,
            'eur_usd' => $eurUsd->rate,
            'usd_iqd_rate_id' => $usdIqd->id,
            'eur_usd_rate_id' => $eurUsd->id,
        ]);
    }

    private function validRate(string $rate): string
    {
        try {
            $value = BigDecimal::of(str_replace(',', '', trim($rate)));
        } catch (MathException) {
            throw new MoneyRuleViolation(__('":value" is not a valid rate.', ['value' => $rate]), 'rate');
        }

        if (! $value->isPositive()) {
            throw new MoneyRuleViolation(__('A rate must be above zero.'), 'rate');
        }

        return (string) $value->toScale(8, RoundingMode::HalfUp);
    }

    private function authorize(User $by, string $pair): void
    {
        $permission = self::PAIRS[$pair] ?? throw new MoneyRuleViolation(__('Only USD/IQD and EUR/USD rates are kept.'));

        if (! $by->can($permission)) {
            throw new AuthorizationException(__('You are not allowed to set the :pair rate.', ['pair' => $pair]));
        }
    }
}
