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
use App\Services\Clinical\SurgeryProductPicker;
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
use Filament\Schemas\Components\Utilities\Set;
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
            Section::make(__('Surgery'))->columnSpanFull()->columns(['default' => 1, 'md' => 3])->schema([
                Select::make('branch_id')->label(__('Branch'))->options(fn () => ServiceAction::branchOptions('surgeries.create'))->required()->live()->native(false)
                    ->default(fn () => User::focusedBranchId() ?? ServiceAction::user()->primary_branch_id),
                DatePicker::make('surgery_date')->label(__('Date'))->default(fn () => now(config('orthotrack.timezone'))->toDateString())->required()->native(false)->displayFormat('Y-m-d')->maxDate(now()->addDay()),
                Select::make('surgery_type_id')->label(__('Surgery'))->options(fn () => SurgeryType::options())->required()->native(false),
                Select::make('customer_id')->label(__('Hospital'))->options(fn () => Customer::options())->searchable()->required()->native(false),
                ToggleButtons::make('laterality')->label(__('Side'))->options(Laterality::options())->default(Laterality::NotApplicable->value)->inline(),
                Select::make('kit_checkout_id')->label(__('Kit used (optional)'))->native(false)
                    ->options(fn (Get $get) => KitCheckout::query()->with('kit')->where('status', KitCheckoutStatus::Out)->where('branch_id', (int) $get('branch_id'))
                        ->get()->mapWithKeys(fn (KitCheckout $c) => [$c->id => $c->label()])),
                TextInput::make('fee_amount')->label(__('Surgery fee (optional)'))->numeric()->minValue(0)->extraInputAttributes(['dir' => 'ltr'])
                    ->helperText(__('An invoice for this amount is made when the surgery is completed.')),
                Select::make('fee_currency')->label(__('Currency'))->native(false)->requiredWith('fee_amount')
                    ->options(array_combine(config('orthotrack.currencies'), config('orthotrack.currencies')))
                    ->default(fn () => ServiceAction::user()->primaryBranch->default_currency ?? 'USD'),
                Select::make('patient_id')->label(__('Patient (optional)'))->searchable()->native(false)->columnSpan(2)
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
                Select::make('lead_doctor_id')->label(__('Lead surgeon'))->options(fn (Get $get) => User::doctorOptions((int) $get('branch_id') ?: null))->default(fn () => ServiceAction::user()->id)->required()->native(false),
                Select::make('technician_ids')->label(__('Technicians'))->options(fn (Get $get) => User::technicianOptions((int) $get('branch_id') ?: null))->multiple()->native(false)->columnSpan(2)
                    ->helperText(fn (Get $get) => User::technicianOptions((int) $get('branch_id') ?: null) === [] ? __('No technicians in this branch yet — add them under Clinical → Technicians.') : null),
                Textarea::make('notes')->label(__('Notes'))->rows(2)->columnSpanFull(),
            ]),
            Section::make(__('Implants and products used'))->columnSpanFull()->schema([
                Repeater::make('items')->hiddenLabel()->defaultItems(1)->minItems(1)->columns(['default' => 1, 'md' => 4])
                    ->addActionLabel(__('Add an item'))
                    ->schema([
                        Select::make('category_id')->label(__('Category'))->native(false)->searchable()->live()
                            ->options(fn () => app(SurgeryProductPicker::class)->categories())
                            ->afterStateUpdated(fn (Set $set) => $set('product_id', null)),
                        Select::make('product_id')->label(__('Product'))->searchable()->native(false)->live()->columnSpan(['default' => 1, 'md' => 2])
                            ->helperText(__('Only products with stock in this branch.'))
                            ->options(fn (Get $get) => filled($get('../../branch_id'))
                                ? app(SurgeryProductPicker::class)->productOptions((int) $get('../../branch_id'), filled($get('category_id')) ? (int) $get('category_id') : null)
                                : [])
                            ->getSearchResultsUsing(fn (string $search, Get $get) => filled($get('../../branch_id'))
                                ? app(SurgeryProductPicker::class)->productOptions((int) $get('../../branch_id'), filled($get('category_id')) ? (int) $get('category_id') : null, $search)
                                : [])
                            ->getOptionLabelUsing(fn ($value) => Product::query()->find($value)?->label())
                            ->afterStateUpdated(fn (Set $set) => $set('stock_unit_id', null)),
                        Select::make('stock_unit_id')->label(__('Serial'))->native(false)->searchable()
                            ->visible(fn (Get $get) => self::isSerialProduct($get('product_id')))
                            ->required(fn (Get $get) => self::isSerialProduct($get('product_id')))
                            ->options(fn (Get $get) => filled($get('product_id')) && filled($get('../../branch_id'))
                                ? app(SurgeryProductPicker::class)->unitOptions((int) $get('../../branch_id'), (int) $get('product_id'))
                                : []),
                        TextInput::make('quantity')->label(__('Quantity'))->numeric()->integer()->minValue(1)->default(1)
                            ->hidden(fn (Get $get) => self::isSerialProduct($get('product_id'))),
                        TextInput::make('raw')->label(__('or scan serial / barcode'))->columnSpan(['default' => 1, 'md' => 4])
                            ->extraInputAttributes(['dir' => 'ltr', 'class' => 'ot-code', 'data-scan-field' => true])
                            ->requiredWithout('product_id'),
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
        // A line is a picked serial, a picked lot product with a quantity, or a scanned code.
        $data['items'] = array_values(array_map(fn (array $line) => match (true) {
            filled($line['stock_unit_id'] ?? null) => ['stock_unit_id' => (int) $line['stock_unit_id'], 'quantity' => 1],
            filled($line['product_id'] ?? null) && ! self::isSerialProduct($line['product_id']) => ['product_id' => (int) $line['product_id'], 'quantity' => (int) ($line['quantity'] ?? 1)],
            default => ['raw' => trim((string) ($line['raw'] ?? '')), 'quantity' => (int) ($line['quantity'] ?? 1)],
        }, (array) ($data['items'] ?? [])));

        try {
            $surgery = app(SurgeryService::class)->sync($data, ServiceAction::user());
        } catch (StockRuleViolation|AuthorizationException $e) {
            throw ValidationException::withMessages(['data.items' => $e->getMessage()]);
        }

        $this->notifyResult($surgery);

        return $surgery;
    }

    private static function isSerialProduct(mixed $productId): bool
    {
        return filled($productId) && (bool) Product::query()->find((int) $productId)?->isSerialTracked();
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
