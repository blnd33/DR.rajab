<?php

namespace App\Filament\Admin\Resources\CreditNotes;

use App\Enums\CreditNoteDirection;
use App\Enums\CreditNoteStatus;
use App\Filament\Admin\Resources\CreditNotes\Pages\ManageCreditNotes;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Filament\Admin\Tables\Columns\LocalDateTimeColumn;
use App\Models\CreditNote;
use App\Support\Locale\Display;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Credit from suppliers for returned items (Module 14 → 7): lowers what we owe them.
 */
class CreditNoteResource extends Resource
{
    protected static ?string $model = CreditNote::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptRefund;

    protected static ?int $navigationSort = 48;

    public static function getNavigationGroup(): ?string
    {
        return __('Finance');
    }

    public static function getModelLabel(): string
    {
        return __('Credit note');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Credit notes');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['party', 'source', 'creator']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                CodeColumn::make('number')->label(__('Number'))->searchable(),
                TextColumn::make('direction')->label(__('Type'))->badge()->color('gray'),
                TextColumn::make('party_name')->label(__('Supplier / customer'))->state(fn (CreditNote $r) => (string) ($r->party?->getAttribute('name') ?? '—')),
                TextColumn::make('source_number')->label(__('For'))->extraAttributes(['dir' => 'ltr'])
                    ->state(fn (CreditNote $r) => (string) ($r->source?->getAttribute('number') ?? '—')),
                TextColumn::make('amount')->label(__('Amount'))->alignEnd()->weight('semibold')->formatStateUsing(fn ($state, CreditNote $r) => Display::money($state, $r->currency)),
                CodeColumn::make('reference')->label(__('Supplier reference'))->placeholder('—'),
                TextColumn::make('status')->label(__('Status'))->badge(),
                LocalDateTimeColumn::make('created_at')->label(__('Recorded')),
            ])
            ->filters([
                SelectFilter::make('direction')->label(__('Type'))->options(CreditNoteDirection::options()),
                SelectFilter::make('status')->label(__('Status'))->options(CreditNoteStatus::options()),
            ])
            ->emptyStateHeading(__('No credit notes yet'))
            ->emptyStateDescription(__('Record credit from a sent supplier return.'));
    }

    public static function getPages(): array
    {
        return ['index' => ManageCreditNotes::route('/')];
    }
}
