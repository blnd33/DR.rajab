<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Support\DocumentNavigation;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\CompanyDocument;
use App\Models\DocumentFolder;
use App\Models\User;
use App\Services\Documents\CompanyDocumentService;
use App\Support\Locale\Display;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Url;

/**
 * One document folder (a side-menu entry such as Catalogues → Lima or Shipment documents → AWB):
 * its documents with their files, upload, edit, archive. Nothing is deleted.
 */
class DocumentFolderPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.table-page';

    /**
     * One side-menu entry per active folder, in its section (read each time the menu is built).
     */
    public static function registerNavigationItems(): void
    {
        if (static::canAccess()) {
            Filament::getCurrentOrDefaultPanel()->navigationItems(DocumentNavigation::items());
        }
    }

    #[Url]
    public ?int $folder = null;

    public ?DocumentFolder $record = null;

    public static function getSlug(?Panel $panel = null): string
    {
        return 'documents';
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('documents.view');
    }

    public function mount(): void
    {
        $this->record = DocumentFolder::query()->findOrFail((int) $this->folder);
        abort_unless($this->record->is_active || ServiceAction::user()->can('documents.folders'), 404);
    }

    public function getTitle(): string
    {
        return (string) $this->record?->name;
    }

    public function getSubheading(): ?string
    {
        return $this->record?->section->getLabel();
    }

    /**
     * @return list<mixed>
     */
    public static function fields(bool $creating = true): array
    {
        return [
            TextInput::make('title')->label(__('Title'))->required()->maxLength(191)->columnSpanFull(),
            TextInput::make('reference')->label(__('Reference number'))->maxLength(120)
                ->helperText(__('e.g. the AWB, invoice or registration number.'))->extraInputAttributes(['dir' => 'ltr']),
            DatePicker::make('document_date')->label(__('Document date'))->native(false)->displayFormat('Y-m-d'),
            DatePicker::make('expires_on')->label(__('Expires on'))->native(false)->displayFormat('Y-m-d')
                ->helperText(__('Optional — for licences, registrations and certificates.')),
            Textarea::make('notes')->label(__('Notes'))->rows(2)->columnSpanFull(),
            FileUpload::make('files')->label($creating ? __('Files') : __('Add files'))->multiple()->required($creating)
                ->disk('local')->directory('tmp/documents')->visibility('private')->storeFileNamesIn('file_names')
                ->acceptedFileTypes(CompanyDocumentService::FILE_TYPES)->maxSize(CompanyDocumentService::MAX_KB)->columnSpanFull()
                ->helperText(__('PDF, Word, Excel, photos or zip, up to 50 MB each.')),
        ];
    }

    /**
     * The uploaded files of a form, as files the service can store; the temporary copies are
     * removed by forgetUploads() once stored.
     *
     * @param  array<string, mixed>  $data
     * @return list<UploadedFile>
     */
    public static function uploads(array $data): array
    {
        $names = (array) ($data['file_names'] ?? []);
        $files = [];

        foreach ((array) ($data['files'] ?? []) as $key => $path) {
            if (is_string($path) && Storage::disk('local')->exists($path)) {
                $files[] = new UploadedFile(Storage::disk('local')->path($path), (string) ($names[$key] ?? $names[$path] ?? basename($path)),
                    Storage::disk('local')->mimeType($path) ?: null, null, true);
            }
        }

        return $files;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function forgetUploads(array $data): void
    {
        foreach ((array) ($data['files'] ?? []) as $path) {
            if (is_string($path) && str_starts_with($path, 'tmp/documents/')) {
                Storage::disk('local')->delete($path);
            }
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('upload')->label(__('Upload a document'))->icon(Heroicon::OutlinedArrowUpTray)
                ->visible(fn () => ServiceAction::user()->can('documents.upload') && $this->record?->is_active)
                ->modalHeading(fn () => __('Upload to :folder', ['folder' => $this->record?->name]))
                ->schema(static::fields())
                ->action(function (array $data, Action $action) {
                    ServiceAction::run($action, fn ($u) => app(CompanyDocumentService::class)->upload($this->record, $data, static::uploads($data), $u), __('Document saved.'));
                    static::forgetUploads($data);
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => CompanyDocument::query()->where('document_folder_id', $this->record?->id)->with(['files', 'creator']))
            ->defaultSort('document_date', 'desc')
            ->columns([
                TextColumn::make('title')->label(__('Title'))->searchable()->sortable()->weight('semibold')->limit(60)
                    ->description(fn (CompanyDocument $r) => $r->reference),
                TextColumn::make('reference')->label(__('Reference number'))->searchable()->toggleable(isToggledHiddenByDefault: true),
                ViewColumn::make('files')->label(__('Files'))->view('filament.documents.files-column'),
                TextColumn::make('document_date')->label(__('Document date'))->sortable()->placeholder('—')->formatStateUsing(fn ($state) => Display::date($state)),
                TextColumn::make('expires_on')->label(__('Expires on'))->sortable()->placeholder('—')->badge()
                    ->formatStateUsing(fn ($state) => Display::date($state))
                    ->color(fn (CompanyDocument $r) => $r->isExpired() ? 'danger' : ($r->expires_on?->lt(now()->addDays(30)) ? 'warning' : 'gray')),
                TextColumn::make('notes')->label(__('Notes'))->placeholder('—')->limit(40)->searchable()->toggleable(),
                TextColumn::make('creator.name')->label(__('Uploaded by'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label(__('Uploaded'))->sortable()->formatStateUsing(fn ($state) => Display::date($state))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('archive_reason')->label(__('Why archived'))->color('gray')->placeholder('—')->limit(60)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('archived')->label(__('Archived'))->default(false)
                    ->queries(true: fn (Builder $q) => $q->whereNotNull('archived_at'), false: fn (Builder $q) => $q->whereNull('archived_at')),
            ])
            ->recordActions([ActionGroup::make([
                Action::make('edit')->label(__('Edit'))->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn (CompanyDocument $r) => ! $r->isArchived() && ServiceAction::user()->can('documents.upload'))
                    ->fillForm(fn (CompanyDocument $r) => [
                        ...$r->only(['title', 'reference', 'notes']),
                        'document_date' => $r->document_date?->toDateString(),
                        'expires_on' => $r->expires_on?->toDateString(),
                    ])
                    ->schema(static::fields(creating: false))
                    ->action(function (CompanyDocument $r, array $data, Action $action) {
                        ServiceAction::run($action, fn ($u) => app(CompanyDocumentService::class)->update($r, $data, $u, static::uploads($data)), __('Document saved.'));
                        static::forgetUploads($data);
                    }),
                Action::make('archive')->label(__('Archive'))->icon(Heroicon::OutlinedArchiveBox)->color('danger')
                    ->visible(fn (CompanyDocument $r) => ! $r->isArchived() && ServiceAction::user()->can('documents.upload'))
                    ->requiresConfirmation()
                    ->modalDescription(__('The document and its files are kept; it only leaves this list (see the Archived filter).'))
                    ->schema([Textarea::make('reason')->label(__('Reason'))->required()->rows(2)])
                    ->action(fn (CompanyDocument $r, array $data, Action $action) => ServiceAction::run($action,
                        fn ($u) => app(CompanyDocumentService::class)->archive($r, (string) $data['reason'], $u), __('Document archived.'))),
                Action::make('restore')->label(__('Bring back'))->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->visible(fn (CompanyDocument $r) => $r->isArchived() && ServiceAction::user()->can('documents.upload'))
                    ->action(fn (CompanyDocument $r, Action $action) => ServiceAction::run($action,
                        fn ($u) => app(CompanyDocumentService::class)->restore($r, $u), __('Document saved.'))),
            ])])
            ->emptyStateHeading(__('No documents yet'))
            ->emptyStateDescription(fn () => ServiceAction::user()->can('documents.upload') ? __('Use "Upload a document" to add the first one.') : null);
    }
}
