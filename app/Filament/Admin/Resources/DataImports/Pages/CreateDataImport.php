<?php

namespace App\Filament\Admin\Resources\DataImports\Pages;

use App\Enums\ImportType;
use App\Filament\Admin\Resources\DataImports\DataImportResource;
use App\Models\Branch;
use App\Models\User;
use App\Services\Imports\ImportPipeline;
use App\Services\Permissions\BranchScopeResolver;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Step 1 of the import: choose what to import, download the template, upload the file.
 * Checking and the preview happen on the next page (queued).
 */
class CreateDataImport extends CreateRecord
{
    protected static string $resource = DataImportResource::class;

    protected static bool $canCreateAnother = false;

    public function getTitle(): string
    {
        return __('New Excel import');
    }

    public function mount(): void
    {
        parent::mount();

        $type = ImportType::tryFrom((string) request()->query('type'));

        if ($type !== null && app(ImportPipeline::class)->canImport($this->user(), $type)) {
            $this->form->fill(['type' => $type->value]);
        }
    }

    public function form(Schema $schema): Schema
    {
        $pipeline = app(ImportPipeline::class);
        $types = collect(ImportType::cases())->filter(fn (ImportType $t) => $pipeline->canImport($this->user(), $t));

        return $schema->columns(1)->components([
            Section::make(__('1. What do you want to import?'))
                ->schema([
                    ToggleButtons::make('type')
                        ->hiddenLabel()
                        ->options($types->mapWithKeys(fn (ImportType $t) => [$t->value => $t->getLabel()])->all())
                        ->icons($types->mapWithKeys(fn (ImportType $t) => [$t->value => $t->getIcon()])->all())
                        ->default($types->first()?->value)
                        ->inline()
                        ->required()
                        ->live(),
                ]),

            Section::make(__('2. Fill the template'))
                ->schema([
                    Text::make(__('Use the template: keep the header row, one item per row. The second sheet explains every column in English, Arabic and Kurdish.')),
                    Actions::make([
                        Action::make('template')
                            ->label(__('Download template'))
                            ->icon(Heroicon::OutlinedArrowDownTray)
                            ->color('gray')
                            ->action(function (Get $get) {
                                $type = ImportType::tryFrom((string) $get('type')) ?? ImportType::Products;

                                return Excel::download(app(ImportPipeline::class)->template($type), 'orthotrack-template-'.str_replace('_', '-', $type->value).'.xlsx');
                            }),
                    ]),
                ]),

            Section::make(__('3. Upload the file'))
                ->schema([
                    Select::make('branch_id')
                        ->label(__('Default branch'))
                        ->helperText(__('Used for rows with an empty Branch column.'))
                        ->options(fn () => app(BranchScopeResolver::class)->selectableBranches($this->user(), 'stock.import')->get()->mapWithKeys(fn (Branch $b) => [$b->id => $b->label()]))
                        ->visible(fn (Get $get) => $get('type') === ImportType::Stock->value)
                        ->native(false),
                    FileUpload::make('original_path')
                        ->label(__('Excel file'))
                        ->helperText(__('.xlsx, .xls or .csv — up to 20 MB. Nothing is changed until you confirm the preview.'))
                        ->disk(ImportPipeline::DISK)
                        ->directory('imports/'.now()->format('Y/m'))
                        ->visibility('private')
                        ->storeFileNamesIn('original_filename')
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/vnd.ms-excel',
                            'text/csv',
                            'text/plain',
                        ])
                        ->maxSize(20480)
                        ->required(),
                    Hidden::make('original_filename'),
                ]),
        ]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $type = ImportType::from($data['type']);
        $branch = filled($data['branch_id'] ?? null) ? Branch::query()->find($data['branch_id']) : null;

        return app(ImportPipeline::class)->startStored(
            $type,
            (string) $data['original_path'],
            (string) ($data['original_filename'] ?? basename((string) $data['original_path'])),
            $this->user(),
            $branch,
        );
    }

    protected function getRedirectUrl(): string
    {
        return DataImportResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label(__('Upload and check'));
    }

    private function user(): User
    {
        /** @var User */
        return Auth::user();
    }
}
