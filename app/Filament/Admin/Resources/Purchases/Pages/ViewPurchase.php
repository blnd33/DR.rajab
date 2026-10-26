<?php

namespace App\Filament\Admin\Resources\Purchases\Pages;

use App\Enums\AllocationMethod;
use App\Enums\LandedCostType;
use App\Enums\PaymentStatus;
use App\Enums\PurchaseStatus;
use App\Enums\ReceiptMethod;
use App\Enums\ReceivingStatus;
use App\Exceptions\Domain\StockRuleViolation;
use App\Filament\Admin\Resources\Purchases\PurchaseResource;
use App\Filament\Admin\Resources\SupplierPayments\SupplierPaymentResource;
use App\Filament\Admin\Support\RefreshesInfolist;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\Currency;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Services\Purchasing\LandedCostService;
use App\Services\Purchasing\PurchaseReceiveService;
use App\Services\Purchasing\PurchaseService;
use App\Support\Locale\Display;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ViewPurchase extends ViewRecord
{
    use RefreshesInfolist;

    protected static string $resource = PurchaseResource::class;

    public function getTitle(): string
    {
        return $this->purchase()->number.' · '.$this->purchase()->supplier->name;
    }

    protected function getHeaderActions(): array
    {
        $user = ServiceAction::user();
        $branch = fn () => $this->purchase()->branch_id;
        $confirmed = fn () => $this->purchase()->status === PurchaseStatus::Confirmed;

        return [
            Action::make('confirm')
                ->label(__('Confirm invoice'))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->visible(fn () => $this->purchase()->status === PurchaseStatus::Draft && $user->canInBranch('purchases.create', $branch()))
                ->requiresConfirmation()
                ->modalDescription(__('The invoice is added to what you owe the supplier.'))
                ->action(fn (Action $action) => $this->run($action, fn ($u) => app(PurchaseService::class)->confirm($this->purchase(), $u), __('Invoice confirmed.'))),

            Action::make('receive')
                ->label(__('Receive items'))
                ->icon(Heroicon::OutlinedInboxArrowDown)
                ->visible(fn () => $confirmed() && $this->purchase()->receiving_status !== ReceivingStatus::Received && $user->canInBranch('purchases.receive', $branch()))
                ->modalWidth('5xl')
                ->schema([
                    Textarea::make('scans')->label(__('Scan codes (one per line)'))->rows(3)
                        ->helperText(__('Each scan becomes one line below the ones you type.'))
                        ->extraInputAttributes(['dir' => 'ltr', 'class' => 'ot-code', 'data-scan-field' => true]),
                    Repeater::make('lines')->label(__('Or type the lines'))->defaultItems(0)->reorderable(false)->columns(['default' => 2, 'md' => 5])
                        ->addActionLabel(__('Add a line'))
                        ->schema([
                            Select::make('purchase_item_id')->label(__('Product'))->required()->native(false)->columnSpan(2)
                                ->options(fn () => $this->openItems()),
                            TextInput::make('serial')->label(__('Serial'))->extraInputAttributes(['dir' => 'ltr', 'class' => 'ot-code']),
                            TextInput::make('lot')->label(__('Lot'))->extraInputAttributes(['dir' => 'ltr', 'class' => 'ot-code']),
                            DatePicker::make('expiry_date')->label(__('Expiry'))->native(false)->displayFormat('Y-m-d'),
                            TextInput::make('quantity')->label(__('Quantity (lots)'))->numeric()->integer()->minValue(1)->default(1),
                        ]),
                    Textarea::make('notes')->label(__('Notes'))->rows(2),
                ])
                ->action(function (array $data, Action $action) {
                    $service = app(PurchaseReceiveService::class);
                    $lines = [];
                    $scans = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($data['scans'] ?? '')) ?: [])));

                    try {
                        foreach ($scans as $code) {
                            $lines[] = $service->lineFromScan($this->purchase(), $code);
                        }
                    } catch (StockRuleViolation $e) {
                        ServiceAction::run($action, fn () => throw $e);

                        return;
                    }

                    foreach ($data['lines'] ?? [] as $line) {
                        $lines[] = $line;
                    }

                    $receipt = $this->run($action, fn ($u) => $service->receive($this->purchase(), $lines, $u, $scans !== [] ? ReceiptMethod::Scan : ReceiptMethod::Manual, $data['notes'] ?? null));

                    if ($receipt !== null) {
                        Notification::make()->title(__('Received into stock (:number).', ['number' => $receipt->number]))->success()->send();
                    }
                }),

            Action::make('pay')
                ->label(__('Pay'))
                ->icon(Heroicon::OutlinedBanknotes)
                ->color('success')
                ->visible(fn () => $confirmed() && $this->purchase()->payment_status !== PaymentStatus::Paid && $user->can('supplier_payments.create'))
                ->schema(fn () => SupplierPaymentResource::paySchema($this->purchase()))
                ->action(function (array $data, Action $action) {
                    SupplierPaymentResource::pay($this->purchase()->supplier, $data, $action, $this->purchase());
                    $this->refreshPurchase();
                }),

            ActionGroup::make([
                Action::make('landed_cost')
                    ->label(__('Add landed cost'))
                    ->icon(Heroicon::OutlinedTruck)
                    ->visible(fn () => $confirmed() && $user->canInBranch('purchases.landed_costs', $branch()))
                    ->modalDescription(__('Shipping, customs, clearance or bank fees are spread over the items, so their cost is the real cost.'))
                    ->schema([
                        Select::make('type')->label(__('Type'))->options(LandedCostType::options())->required()->native(false),
                        TextInput::make('amount')->label(__('Amount'))->numeric()->minValue(0)->required(),
                        Select::make('currency')->label(__('Currency'))->options(fn () => Currency::options())->default(fn () => $this->purchase()->currency)->required()->native(false),
                        Select::make('allocation_method')->label(__('Spread by'))->options(AllocationMethod::options())->default(AllocationMethod::Value->value)->required()->native(false),
                        Select::make('payee_supplier_id')->label(__('Owed to (shipper / broker)'))->options(fn () => Supplier::options())->searchable()->native(false)
                            ->helperText(__('Empty = paid already, nothing owed.')),
                        Textarea::make('note')->label(__('Note'))->rows(2),
                    ])
                    ->action(fn (array $data, Action $action) => $this->run($action, fn ($u) => app(LandedCostService::class)->add($this->purchase(), $data, $u), __('Landed cost added to the item costs.'))),
                Action::make('attach')
                    ->label(__('Attach the invoice file'))
                    ->icon(Heroicon::OutlinedPaperClip)
                    ->visible(fn () => $this->purchase()->status !== PurchaseStatus::Cancelled && $user->canInBranch('purchases.create', $branch()))
                    ->schema([FileUpload::make('file')->label(__('File (photo or PDF)'))->disk('local')->directory('tmp/purchases')->acceptedFileTypes(['image/*', 'application/pdf'])->maxSize(10240)->required()])
                    ->action(function (array $data, Action $action) {
                        $path = (string) $data['file'];
                        $file = new UploadedFile(Storage::disk('local')->path($path), basename($path), Storage::disk('local')->mimeType($path) ?: null, null, true);
                        $this->run($action, fn ($u) => app(PurchaseService::class)->attachInvoice($this->purchase(), $file, $u), __('File attached.'));
                    }),
                Action::make('cancel')
                    ->label(__('Cancel purchase'))
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('danger')
                    ->visible(fn () => $this->purchase()->status !== PurchaseStatus::Cancelled && $user->canInBranch('purchases.create', $branch()))
                    ->schema([Textarea::make('reason')->label(__('Reason'))->required()->rows(2)])
                    ->action(fn (array $data, Action $action) => $this->run($action, fn ($u) => app(PurchaseService::class)->cancel($this->purchase(), $data['reason'], $u), __('Purchase cancelled.'))),
            ])->button()->color('gray')->label(__('More')),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function openItems(): array
    {
        return $this->purchase()->items()->with('product')->whereColumn('quantity_received', '<', 'quantity')->get()
            ->mapWithKeys(fn (PurchaseItem $i) => [$i->id => $i->product->label().' · '.__(':left left', ['left' => Display::number($i->quantity - $i->quantity_received)])])
            ->all();
    }

    private function run(Action $action, callable $callback, ?string $success = null): mixed
    {
        $result = ServiceAction::run($action, $callback, $success);
        $this->refreshPurchase();

        return $result;
    }

    public function refreshPurchase(): void
    {
        $this->record = $this->purchase()->fresh();
        $this->dispatch('purchase-updated');
    }

    private function purchase(): Purchase
    {
        /** @var Purchase */
        return $this->getRecord();
    }
}
