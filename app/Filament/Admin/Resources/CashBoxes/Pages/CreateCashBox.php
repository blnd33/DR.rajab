<?php

namespace App\Filament\Admin\Resources\CashBoxes\Pages;

use App\Enums\CashBoxType;
use App\Exceptions\Domain\StockRuleViolation;
use App\Filament\Admin\Forms\TranslatableInput;
use App\Filament\Admin\Resources\CashBoxes\CashBoxResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\Branch;
use App\Models\Currency;
use App\Services\Money\CashBoxService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateCashBox extends CreateRecord
{
    protected static string $resource = CashBoxResource::class;

    protected static bool $canCreateAnother = false;

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            ToggleButtons::make('type')->label(__('Type'))->options(CashBoxType::options())->default(CashBoxType::Cash->value)->inline()->live()->required(),
            Select::make('branch_id')->label(__('Station'))->options(fn () => ServiceAction::branchOptions('cash_boxes.manage'))->native(false)
                ->placeholder(__('Head office'))->helperText(__('Empty = head office.')),
            TranslatableInput::make('name', __('Name'))->columnSpanFull(),
            Select::make('currency')->label(__('Currency'))->options(fn () => Currency::options())->required()->native(false),
            TextInput::make('opening_balance')->label(__('Opening balance'))->numeric()->minValue(0)->default(0),
            TextInput::make('bank_name')->label(__('Bank'))->maxLength(120)->visible(fn (Get $get) => $get('type') === CashBoxType::Bank->value),
            TextInput::make('account_number')->label(__('Account number'))->maxLength(64)->extraInputAttributes(['dir' => 'ltr'])->visible(fn (Get $get) => $get('type') === CashBoxType::Bank->value),
            TextInput::make('iban')->label(__('IBAN'))->maxLength(64)->extraInputAttributes(['dir' => 'ltr'])->visible(fn (Get $get) => $get('type') === CashBoxType::Bank->value),
            Toggle::make('allow_overdraft')->label(__('May go below zero (overdraft)'))->visible(fn (Get $get) => $get('type') === CashBoxType::Bank->value),
        ]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(CashBoxService::class)->create(filled($data['branch_id'] ?? null) ? Branch::query()->findOrFail($data['branch_id']) : null, $data, ServiceAction::user());
        } catch (StockRuleViolation $e) {
            throw ValidationException::withMessages(['data.'.($e->field ?? 'currency') => $e->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return CashBoxResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
