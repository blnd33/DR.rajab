<?php

namespace App\Filament\Admin\Resources\Kits\Pages;

use App\Exceptions\Domain\StockRuleViolation;
use App\Filament\Admin\Resources\Kits\KitResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\Branch;
use App\Models\KitType;
use App\Services\Assets\KitService;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateKit extends CreateRecord
{
    protected static string $resource = KitResource::class;

    protected static bool $canCreateAnother = false;

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('kit_type_id')->label(__('Kit type'))->options(fn () => KitType::options())->required()->native(false),
            Select::make('home_branch_id')->label(__('Home branch'))->options(fn () => ServiceAction::branchOptions('kits.manage'))->required()->native(false)
                ->default(fn () => ServiceAction::user()->primary_branch_id),
            TextInput::make('code')->label(__('Code'))->maxLength(32)->unique('kits', 'code')
                ->helperText(__('Leave empty for the next number.'))
                ->extraInputAttributes(['dir' => 'ltr', 'class' => 'ot-code', 'style' => 'text-transform: uppercase']),
            TextInput::make('barcode')->label(__('Barcode on the label'))->maxLength(128)->unique('kits', 'barcode')
                ->helperText(__('Empty = the code. Fill in if the case already has a label.'))
                ->extraInputAttributes(['dir' => 'ltr', 'class' => 'ot-code']),
            FileUpload::make('photo_path')->label(__('Photo'))->image()->disk('public')->directory('kits')->maxSize(8192),
            Textarea::make('notes')->label(__('Notes'))->rows(3),
        ]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(KitService::class)->create(Branch::query()->findOrFail($data['home_branch_id']), $data, ServiceAction::user());
        } catch (StockRuleViolation $e) {
            throw ValidationException::withMessages(['data.kit_type_id' => $e->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return KitResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
