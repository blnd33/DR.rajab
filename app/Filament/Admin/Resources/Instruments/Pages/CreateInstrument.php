<?php

namespace App\Filament\Admin\Resources\Instruments\Pages;

use App\Filament\Admin\Resources\Instruments\InstrumentResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\Branch;
use App\Models\InstrumentType;
use App\Services\Assets\InstrumentService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class CreateInstrument extends CreateRecord
{
    protected static string $resource = InstrumentResource::class;

    protected static bool $canCreateAnother = false;

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('instrument_type_id')->label(__('Instrument type'))->options(fn () => InstrumentType::options())->required()->native(false),
            Select::make('branch_id')->label(__('Station'))->options(fn () => ServiceAction::branchOptions('instruments.manage'))->required()->native(false)
                ->default(fn () => ServiceAction::user()->primary_branch_id),
            TextInput::make('code')->label(__('Code'))->maxLength(32)->unique('instruments', 'code')
                ->helperText(__('Leave empty for the next number.'))
                ->extraInputAttributes(['dir' => 'ltr', 'class' => 'ot-code', 'style' => 'text-transform: uppercase']),
            TextInput::make('barcode')->label(__('Barcode on the label'))->maxLength(128)->unique('instruments', 'barcode')
                ->helperText(__('Empty = the code. Fill in if the instrument already has a label.'))
                ->extraInputAttributes(['dir' => 'ltr', 'class' => 'ot-code']),
            ...InstrumentResource::detailFields(),
        ]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        return app(InstrumentService::class)->create(Branch::query()->findOrFail($data['branch_id']), $data, ServiceAction::user());
    }

    protected function getRedirectUrl(): string
    {
        return InstrumentResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
