<?php

namespace App\Filament\Admin\Resources\UnrecognizedScans;

use App\Enums\ScanContext;
use App\Filament\Admin\Resources\UnrecognizedScans\Pages\ListUnrecognizedScans;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Filament\Admin\Tables\Columns\LocalDateTimeColumn;
use App\Models\UnrecognizedScan;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Codes no parser understood, with the raw bytes in hex (GS / FNC1 visible), so a new
 * barcode format can be added when the client's real boxes arrive.
 */
class UnrecognizedScanResource extends Resource
{
    protected static ?string $model = UnrecognizedScan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    protected static ?int $navigationSort = 83;

    public static function getNavigationGroup(): ?string
    {
        return __('System');
    }

    public static function getModelLabel(): string
    {
        return __('Unrecognised scan');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Unrecognised scans');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['branch', 'user']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                LocalDateTimeColumn::make('created_at')->label(__('When'))->sortable(),
                CodeColumn::make('raw')->label(__('Code'))->state(fn (UnrecognizedScan $record) => $record->visibleRaw())->wrap()->searchable(),
                CodeColumn::make('raw_hex')->label(__('Bytes (hex)'))->limit(48)->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('context')->label(__('Screen'))->badge()->color('gray'),
                TextColumn::make('branch.code')->label(__('Station'))->placeholder('—'),
                TextColumn::make('user.name')->label(__('By'))->placeholder('—'),
            ])
            ->filters([SelectFilter::make('context')->label(__('Screen'))->options(ScanContext::options())])
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle)
            ->emptyStateHeading(__('Every scanned code was recognised'));
    }

    public static function getPages(): array
    {
        return ['index' => ListUnrecognizedScans::route('/')];
    }
}
