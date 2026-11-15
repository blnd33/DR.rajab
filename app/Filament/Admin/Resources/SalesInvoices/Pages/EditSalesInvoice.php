<?php

namespace App\Filament\Admin\Resources\SalesInvoices\Pages;

use App\Enums\InvoiceKind;
use App\Exceptions\Domain\StockRuleViolation;
use App\Filament\Admin\Resources\SalesInvoices\SalesInvoiceResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\Customer;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Services\Sales\InvoiceService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Edit an unpaid invoice (InvoiceService::update): buyer, notes, due date and the amounts.
 * A sale's items stay as they are (stock moved with them).
 */
class EditSalesInvoice extends EditRecord
{
    protected static string $resource = SalesInvoiceResource::class;

    public function getTitle(): string
    {
        return __('Edit invoice :number', ['number' => $this->invoice()->number]);
    }

    public function form(Schema $schema): Schema
    {
        $invoice = $this->invoice();
        $currencies = array_combine(config('orthotrack.currencies'), config('orthotrack.currencies'));
        $surgery = $invoice->kind === InvoiceKind::Surgery;

        return $schema->components([
            Section::make(__('Invoice'))->columnSpanFull()->columns(['default' => 1, 'md' => 2])->schema([
                Select::make('customer_id')->label(__('Hospital / customer'))->options(fn () => Customer::options())->searchable()->native(false)->live(),
                TextInput::make('walk_in_name')->label($surgery ? __('or patient / payer name') : __('or walk-in buyer'))->maxLength(150)
                    ->hidden(fn (Get $get) => filled($get('customer_id'))),
                DatePicker::make('due_date')->label(__('Due date'))->native(false)->displayFormat('Y-m-d'),
                Textarea::make('notes')->label(__('Notes'))->rows(2)->columnSpanFull(),
            ]),
            Section::make(__('Amount'))->columnSpanFull()->columns(['default' => 1, 'md' => 2])->visible($surgery)->schema([
                TextInput::make('amount')->label(__('Surgery fee'))->numeric()->minValue(0)->required($surgery)->extraInputAttributes(['dir' => 'ltr']),
                Select::make('currency')->label(__('Currency'))->options($currencies)->required($surgery)->native(false),
            ]),
            Section::make(__('Amounts'))->columnSpanFull()->visible(! $surgery)
                ->description(__('Change what each line costs the buyer. To change the items themselves, void the sale and make a new one.'))
                ->schema([
                    Repeater::make('lines')->hiddenLabel()->addable(false)->deletable(false)->reorderable(false)->columns(['default' => 1, 'md' => 3])
                        ->schema([
                            TextInput::make('label')->label(__('Item'))->disabled()->dehydrated(false)->columnSpan(['default' => 1, 'md' => 2]),
                            TextInput::make('line_total')->label(fn () => __('Amount (:currency)', ['currency' => $invoice->currency]))
                                ->numeric()->minValue(0)->required()->extraInputAttributes(['dir' => 'ltr']),
                            TextInput::make('id')->hidden(),
                        ]),
                    TextInput::make('discount')->label(__('Discount'))->numeric()->minValue(0)->default(0)->extraInputAttributes(['dir' => 'ltr']),
                ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $invoice = $this->invoice();

        return [
            'customer_id' => $invoice->customer_id,
            'walk_in_name' => $invoice->walk_in_name,
            'due_date' => $invoice->due_date?->toDateString(),
            'notes' => $invoice->notes,
            'amount' => (string) (float) $invoice->total,
            'currency' => $invoice->currency,
            'discount' => (string) (float) $invoice->discount,
            'lines' => $invoice->items()->with(['product', 'stockUnit', 'stockBatch'])->orderBy('id')->get()
                ->map(fn (SalesInvoiceItem $i) => [
                    'id' => $i->id,
                    'label' => trim($i->product->sku.' · '.$i->product->name.' × '.$i->quantity.' '.($i->stockUnit->serial ?? $i->stockBatch->lot ?? '')),
                    'line_total' => (string) (float) $i->line_total,
                ])->values()->all(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var SalesInvoice $record */
        $lines = [];

        foreach ((array) ($data['lines'] ?? []) as $line) {
            if (filled($line['id'] ?? null)) {
                $lines[(int) $line['id']] = (string) $line['line_total'];
            }
        }

        try {
            return app(InvoiceService::class)->update($record, [
                'customer_id' => $data['customer_id'] ?? null,
                'walk_in_name' => $data['walk_in_name'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'amount' => $data['amount'] ?? null,
                'currency' => $data['currency'] ?? null,
                'lines' => $lines,
                'discount' => $data['discount'] ?? '0',
            ], ServiceAction::user());
        } catch (StockRuleViolation $e) {
            throw ValidationException::withMessages(['data.'.($e->field ?? 'amount') => $e->getMessage()]);
        } catch (AuthorizationException $e) {
            throw ValidationException::withMessages(['data.notes' => $e->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        return SalesInvoiceResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    private function invoice(): SalesInvoice
    {
        /** @var SalesInvoice */
        return $this->getRecord();
    }
}
