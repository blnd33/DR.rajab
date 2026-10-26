<?php

namespace App\Services\Money;

use App\Enums\PartyEntryType;
use App\Models\FxSnapshot;
use App\Models\PartyLedgerEntry;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Supplier and customer balances per currency (P4-10), append-only:
 * + increases what is owed (supplier: we owe them; customer: they owe us).
 */
class PartyLedgerService
{
    public function __construct(private readonly FxService $fx) {}

    public function record(Model $party, PartyEntryType $type, string $currency, string $amount, ?Model $reference, ?string $description, ?User $by, ?FxSnapshot $snapshot = null): PartyLedgerEntry
    {
        $entry = new PartyLedgerEntry([
            'entry_type' => $type,
            'currency' => $currency,
            'amount' => (string) BigDecimal::of($amount)->toScale(4, RoundingMode::HalfUp),
            'fx_snapshot_id' => ($snapshot ?? $this->fx->currentOrFail())->id,
            'description' => $description,
            'user_id' => $by?->id,
            'occurred_at' => now(),
        ]);

        $entry->party()->associate($party);

        if ($reference !== null) {
            $entry->reference()->associate($reference);
        }

        $entry->save();

        return $entry;
    }

    /**
     * @return array<string, string> currency => balance (non-zero only)
     */
    public function balances(Model $party): array
    {
        return PartyLedgerEntry::query()
            ->where('party_type', $party->getMorphClass())->where('party_id', $party->getKey())
            ->groupBy('currency')
            ->selectRaw('currency, SUM(amount) AS balance')
            ->pluck('balance', 'currency')
            ->map(fn ($b) => (string) BigDecimal::of((string) $b)->toScale(4, RoundingMode::HalfUp))
            ->filter(fn ($b) => ! BigDecimal::of($b)->isZero())
            ->all();
    }

    /**
     * @return Builder<PartyLedgerEntry>
     */
    public function statement(Model $party, ?string $currency = null): Builder
    {
        return PartyLedgerEntry::query()
            ->where('party_type', $party->getMorphClass())->where('party_id', $party->getKey())
            ->when($currency !== null, fn ($q) => $q->where('currency', $currency))
            ->orderBy('occurred_at')->orderBy('id');
    }
}
