<?php

namespace App\Filament\Admin\Tables\Filters;

use App\Models\ExpiryLevel;
use App\Services\Expiry\ExpiryClassifier;
use App\Services\Stock\StockQuery;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filter units / lots by the configurable expiry level ("Critical", "Expired"…).
 */
class ExpiryLevelFilter extends SelectFilter
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Expiry level'))
            ->options(fn () => app(ExpiryClassifier::class)->levels()->mapWithKeys(fn (ExpiryLevel $level) => [$level->id => "{$level->name} ({$level->rangeLabel()})"])->all())
            ->query(function (Builder $query, array $data) {
                $level = filled($data['value'] ?? null) ? ExpiryLevel::query()->find($data['value']) : null;

                return $level === null ? $query : app(StockQuery::class)->whereExpiryLevel($query, $level);
            });
    }
}
