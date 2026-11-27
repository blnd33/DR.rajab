<?php

namespace App\Filament\Admin\Pages;

use App\Enums\DocumentSection;
use App\Enums\Specialty;
use App\Models\CompanyDocument;
use App\Models\DocumentFolder;
use App\Models\User;
use App\Support\Locale\Display;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Catalogues → All Catalogues: the catalogue documents of every company in one list, with the
 * company and its category; filter by category or company. Uploading stays in each company.
 */
class AllCataloguesPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.table-page';

    protected static bool $shouldRegisterNavigation = false;

    public static function getSlug(?Panel $panel = null): string
    {
        return 'catalogues/all';
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('documents.view');
    }

    public function getTitle(): string
    {
        return __('All catalogues');
    }

    public function getSubheading(): ?string
    {
        return __('The catalogue documents of every company.');
    }

    public function table(Table $table): Table
    {
        $folders = fn () => DocumentFolder::query()->where('section', DocumentSection::Catalogues->value);

        return $table
            ->query(fn () => CompanyDocument::query()
                ->whereIn('document_folder_id', $folders()->select('id'))
                ->with(['files', 'folder']))
            ->defaultSort('document_date', 'desc')
            ->columns([
                TextColumn::make('folder.name')->label(__('Company'))->weight('semibold')->sortable(false)
                    ->url(fn (CompanyDocument $r) => DocumentFolderPage::getUrl(['folder' => $r->document_folder_id])),
                TextColumn::make('folder.specialty')->label(__('Category'))->placeholder(__('Unsorted'))
                    ->formatStateUsing(fn ($state) => $state instanceof Specialty ? $state->getLabel() : $state),
                TextColumn::make('title')->label(__('Title'))->searchable()->sortable()->limit(60)
                    ->description(fn (CompanyDocument $r) => $r->reference),
                ViewColumn::make('files')->label(__('Files'))->view('filament.documents.files-column'),
                TextColumn::make('document_date')->label(__('Document date'))->sortable()->placeholder('—')->formatStateUsing(fn ($state) => Display::date($state)),
                TextColumn::make('notes')->label(__('Notes'))->placeholder('—')->limit(40)->searchable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('specialty')->label(__('Category'))->options(Specialty::options())
                    ->query(fn (Builder $query, array $data) => $query->when(filled($data['value'] ?? null),
                        fn (Builder $q) => $q->whereIn('document_folder_id', DocumentFolder::query()->where('specialty', $data['value'])->select('id')))),
                SelectFilter::make('document_folder_id')->label(__('Company'))
                    ->options(fn () => $folders()->orderBy('sort')->get()->mapWithKeys(fn (DocumentFolder $f) => [$f->id => (string) $f->name])->all()),
                TernaryFilter::make('archived')->label(__('Archived'))->default(false)
                    ->queries(true: fn (Builder $q) => $q->whereNotNull('archived_at'), false: fn (Builder $q) => $q->whereNull('archived_at')),
            ])
            ->emptyStateHeading(__('No catalogue documents yet'));
    }
}
