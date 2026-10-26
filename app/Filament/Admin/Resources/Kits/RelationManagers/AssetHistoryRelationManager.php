<?php

namespace App\Filament\Admin\Resources\Kits\RelationManagers;

use App\Enums\InstrumentStatus;
use App\Enums\KitStatus;
use App\Filament\Admin\Tables\Columns\LocalDateTimeColumn;
use App\Models\AssetMovement;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The asset ledger of a kit or an instrument (append-only, read-only).
 */
class AssetHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'assetMovements';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('History');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'fromBranch', 'toBranch', 'customer']))
            ->columns([
                LocalDateTimeColumn::make('occurred_at')->label(__('When')),
                TextColumn::make('type')->label(__('What'))->badge(),
                TextColumn::make('where')->label(__('Where'))
                    ->state(fn (AssetMovement $r) => $r->from_branch_id !== $r->to_branch_id
                        ? ($r->fromBranch?->code).' → '.($r->toBranch?->code)
                        : $r->toBranch?->code)
                    ->description(fn (AssetMovement $r) => $r->customer?->code),
                TextColumn::make('status_change')->label(__('Status'))
                    ->state(fn (AssetMovement $r) => $r->from_status !== $r->to_status ? self::status($r->from_status).' → '.self::status($r->to_status) : null)->placeholder('—'),
                TextColumn::make('user.name')->label(__('By')),
                TextColumn::make('note')->label(__('Note'))->placeholder('—')->wrap(),
            ])
            ->emptyStateHeading(__('No history yet'));
    }

    private static function status(?string $value): string
    {
        return KitStatus::tryFrom((string) $value)?->getLabel() ?? InstrumentStatus::tryFrom((string) $value)?->getLabel() ?? '—';
    }
}
