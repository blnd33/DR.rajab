<?php

namespace App\Filament\Admin\Resources\Surgeries\Pages;

use App\Enums\Gender;
use App\Enums\KitCheckoutStatus;
use App\Enums\Laterality;
use App\Exceptions\Domain\StockRuleViolation;
use App\Filament\Admin\Resources\Surgeries\SurgeryResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\KitCheckout;
use App\Models\Patient;
use App\Models\Product;
use App\Models\Surgery;
use App\Models\SurgeryType;
use App\Models\User;
use App\Services\Clinical\PatientService;
use App\Services\Clinical\SurgeryService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Log a surgery from the admin (same rules as the phone: SurgeryService::sync).
 */
class CreateSurgery extends CreateRecord
{
    protected static string $resource = SurgeryResource::class;

    protected static bool $canCreateAnother = false;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Surgery'))->columns(3)->schema([
                Select::make('branch_id')->label(__('Branch'))->options(fn () => ServiceAction::branchOptions('surgeries.create'))->required()->live()->native(false)
                    ->default(fn () => ServiceAction::user()->primary_branch_id),
                DatePicker::make('surgery_date')->label(__('Date'))->default(now())->required()->native(false)->displayFormat('Y-m-d')->maxDate(now()->addDay()),
                Select::make('surgery_type_id')->label(__('Surgery'))->options(fn () => SurgeryType::options())->required()->native(false),
                Select::make('customer_id')->label(__('Hospital'))->options(fn () => Customer::options())->searchable()->required()->native(false),
                ToggleButtons::make('laterality')->label(__('Side'))->options(Laterality::options())->default(Laterality::NotApplicable->value)->inline(),
                Select::make('kit_checkout_id')->label(__('Kit used (optional)'))->native(false)
                    ->options(fn (Get $get) => KitCheckout::query()->with('kit')->where('status', KitCheckoutStatus::Out)->where('branch_id', (int) $get('branch_id'))
                        ->get()->mapWithKeys(fn (KitCheckout $c) => [$c->id => $c->label()])),
                Select::make('patient_id')->label(__('Patient'))->required()->searchable()->native(false)->columnSpan(2)
                    ->getSearchResultsUsing(fn (string $search) => app(PatientService::class)->search(ServiceAction::user(), $search)->limit(30)->get()->mapWithKeys(fn (Patient $p) => [$p->id => $p->label()]))
                    ->getOptionLabelUsing(fn ($value) => Patient::query()->find($value)?->label())
                    ->createOptionForm([
                        TextInput::make('full_name')->label(__('Full name'))->required()->maxLength(150),
                        TextInput::make('phone')->label(__('Phone'))->tel()->maxLength(32)->extraInputAttributes(['dir' => 'ltr']),
                        TextInput::make('age')->label(__('Age'))->numeric()->integer()->minValue(0)->maxValue(130),
                        Select::make('gender')->label(__('Gender'))->options(Gender::options())->native(false),
                    ])
                    ->createOptionUsing(function (array $data, Get $get) {
                        $branch = Branch::query()->findOrFail((int) $get('branch_id') ?: ServiceAction::user()->primary_branch_id);

                        return app(PatientService::class)->create($branch, $data, ServiceAction::user())->id;
                    }),
                Select::make('lead_doctor_id')->label(__('Lead surgeon'))->options(fn () => User::doctorOptions())->default(fn () => ServiceAction::user()->id)->required()->native(false),
                Select::make('assistant_ids')->label(__('Assistants'))->options(fn () => User::doctorOptions())->multiple()->native(false)->columnSpan(2),
                Textarea::make('notes')->label(__('Notes'))->rows(2)->columnSpanFull(),
            ]),
            Section::make(__('Implants and products used'))->schema([
                Repeater::make('items')->hiddenLabel()->defaultItems(1)->minItems(1)->columns(4)
                    ->addActionLabel(__('Add an item'))
                    ->schema([
                        TextInput::make('raw')->label(__('Serial or barcode'))->columnSpan(2)
                            ->extraInputAttributes(['dir' => 'ltr', 'class' => 'ot-code', 'data-scan-field' => true])
                            ->requiredWithout('product_id'),
                        Select::make('product_id')->label(__('or lot product'))->searchable()->native(false)
                            ->getSearchResultsUsing(fn (string $search) => Product::query()->active()->where('tracking_type', 'lot')->search($search)->limit(30)->get()->mapWithKeys(fn (Product $p) => [$p->id => $p->label()]))
                            ->getOptionLabelUsing(fn ($value) => Product::query()->find($value)?->label()),
                        TextInput::make('quantity')->label(__('Quantity (lots)'))->numeric()->integer()->minValue(1)->default(1),
                    ]),
                TextInput::make('override_reason')->label(__('Reason to use expired items (only if there are any)'))->maxLength(500),
                Toggle::make('complete')->label(__('Complete now (takes the items out of stock)'))->default(true),
            ]),
        ]);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $data['client_uuid'] = (string) Str::uuid();
        $data['surgery_date'] = substr((string) $data['surgery_date'], 0, 10);
        $data['items'] = array_values(array_map(fn (array $line) => [
            'raw' => trim((string) ($line['raw'] ?? '')),
            'product_id' => $line['product_id'] ?? null,
            'quantity' => (int) ($line['quantity'] ?? 1),
        ], (array) ($data['items'] ?? [])));

        try {
            $surgery = app(SurgeryService::class)->sync($data, ServiceAction::user());
        } catch (StockRuleViolation|AuthorizationException $e) {
            throw ValidationException::withMessages(['data.items' => $e->getMessage()]);
        }

        $this->notifyResult($surgery);

        return $surgery;
    }

    protected function getCreatedNotification(): ?Notification
    {
        return null;
    }

    protected function getRedirectUrl(): string
    {
        return SurgeryResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    private function notifyResult(Surgery $surgery): void
    {
        $surgery->problems === null
            ? Notification::make()->title(__('Surgery :number saved (:status).', ['number' => $surgery->number, 'status' => $surgery->status->getLabel()]))->success()->send()
            : Notification::make()->title(__('Saved as a draft — fix these first:'))->body(implode("\n", $surgery->problems))->warning()->persistent()->send();
    }
}
