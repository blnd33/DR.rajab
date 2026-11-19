<?php

namespace App\Filament\Admin\Resources\DocumentFolders;

use App\Enums\DocumentSection;
use App\Filament\Admin\Forms\TranslatableInput;
use App\Filament\Admin\Pages\DocumentFolderPage;
use App\Filament\Admin\Resources\DocumentFolders\Pages\ManageDocumentFolders;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\DocumentFolder;
use App\Services\Documents\CompanyDocumentService;
use App\Support\Locale\Display;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The document folders of the side menu (Catalogues → Lima, Shipment documents → AWB…): add a
 * new one (e.g. a new catalogue supplier), rename, reorder or hide. Never deleted.
 */
class DocumentFolderResource extends Resource
{
    protected static ?string $model = DocumentFolder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolder;

    protected static ?int $navigationSort = 80;

    public static function getNavigationGroup(): ?string
    {
        return __('Administration');
    }

    public static function getModelLabel(): string
    {
        return __('Document folder');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Document folders');
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('section')->label(__('Menu section'))->options(DocumentSection::options())->required()->native(false),
            TextInput::make('sort')->label(__('Order'))->numeric()->integer()->minValue(0)->default(fn () => DocumentFolder::query()->max('sort') + 1),
            TranslatableInput::make('name', __('Name'))->columnSpanFull(),
            Toggle::make('is_active')->label(__('Shown in the menu'))->default(true)
                ->helperText(__('Hidden folders keep their documents.')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount(['documents' => fn ($q) => $q->whereNull('archived_at')]))
            ->defaultSort(fn ($query) => $query->orderBy('section')->orderBy('sort'))
            ->columns([
                TextColumn::make('section')->label(__('Menu section'))->badge()->color('gray')->sortable(),
                TextColumn::make('name')->label(__('Name'))->weight('semibold')
                    ->url(fn (DocumentFolder $record) => DocumentFolderPage::getUrl(['folder' => $record->id])),
                TextColumn::make('sort')->label(__('Order'))->alignEnd()->sortable(),
                TextColumn::make('documents_count')->label(__('Documents'))->alignEnd()->formatStateUsing(fn ($state) => Display::number($state)),
                IconColumn::make('is_active')->label(__('Shown in the menu'))->boolean(),
            ])
            ->filters([SelectFilter::make('section')->label(__('Menu section'))->options(DocumentSection::options())])
            ->recordActions([
                EditAction::make()->using(fn (DocumentFolder $record, array $data) => app(CompanyDocumentService::class)->saveFolder($record, $data, ServiceAction::user())),
            ])
            ->emptyStateHeading(__('No document folders yet'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDocumentFolders::route('/'),
        ];
    }
}
