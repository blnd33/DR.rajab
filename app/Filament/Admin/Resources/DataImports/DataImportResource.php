<?php

namespace App\Filament\Admin\Resources\DataImports;

use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Filament\Admin\Resources\DataImports\Pages\CreateDataImport;
use App\Filament\Admin\Resources\DataImports\Pages\ListDataImports;
use App\Filament\Admin\Resources\DataImports\Pages\ViewDataImport;
use App\Filament\Admin\Resources\DataImports\RelationManagers\RowsRelationManager;
use App\Filament\Admin\Tables\Columns\LocalDateTimeColumn;
use App\Models\DataImport;
use App\Models\User;
use App\Support\Locale\Display;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Excel imports (Module 23): history, upload, preview with old → new, confirm, progress.
 */
class DataImportResource extends Resource
{
    protected static ?string $model = DataImport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static ?int $navigationSort = 250;

    public static function getNavigationGroup(): ?string
    {
        return __('Stock');
    }

    public static function getModelLabel(): string
    {
        return __('Excel import');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Excel imports');
    }

    public static function getEloquentQuery(): Builder
    {
        /** @var User $user */
        $user = Auth::user();
        $query = parent::getEloquentQuery()->with(['user', 'branch']);

        return $user->isSuperAdmin() ? $query : $query->where('user_id', $user->id);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->poll(fn () => DataImport::query()->whereIn('status', [ImportStatus::Uploaded, ImportStatus::Validating, ImportStatus::Confirmed, ImportStatus::Processing])->exists() ? '3s' : null)
            ->columns([
                LocalDateTimeColumn::make('created_at')->label(__('Uploaded'))->sortable(),
                TextColumn::make('type')->label(__('Type'))->badge()->color('gray'),
                TextColumn::make('original_filename')->label(__('File'))->limit(40)->tooltip(fn (DataImport $record) => $record->original_filename),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('counts')
                    ->label(__('Rows'))
                    ->state(fn (DataImport $record) => __(':new new · :updated updated · :errors errors', [
                        'new' => Display::number($record->new_count),
                        'updated' => Display::number($record->updated_count),
                        'errors' => Display::number($record->error_count),
                    ])),
                TextColumn::make('user.name')->label(__('By'))->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')->label(__('Type'))->options(ImportType::options()),
                SelectFilter::make('status')->label(__('Status'))->options(ImportStatus::options()),
            ])
            ->recordActions([ViewAction::make()->label(__('Open'))])
            ->emptyStateHeading(__('No imports yet'))
            ->emptyStateDescription(__('Download a template, fill it in Excel and upload it here.'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            ViewEntry::make('summary')->hiddenLabel()->view('filament.imports.summary'),
        ]);
    }

    public static function getRelations(): array
    {
        return [RowsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDataImports::route('/'),
            'create' => CreateDataImport::route('/create'),
            'view' => ViewDataImport::route('/{record}'),
        ];
    }
}
