<?php

namespace App\Filament\Admin\Pages;

use App\Enums\ScanContext;
use App\Exceptions\Domain\StockRuleViolation;
use App\Models\Branch;
use App\Models\BranchLocation;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Barcode\ScanResolver;
use App\Services\Barcode\ScanResultKind;
use App\Services\Permissions\BranchScopeResolver;
use App\Services\Stock\Data\ReceiveLine;
use App\Services\Stock\StockMovementService;
use App\Support\Locale\DigitConverter;
use App\Support\Locale\Display;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Opening stock (P1-1): scan boxes (GS1 fills product, lot, expiry, serial) or add lines by
 * hand, then confirm → StockMovementService::receive (ledger type "opening_balance").
 * Purchases (Phase 4) receive through their own screen.
 *
 * @property-read Schema $form
 */
class ReceiveStock extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?int $navigationSort = 5;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public string $scan = '';

    public static function getNavigationGroup(): ?string
    {
        return __('Stock');
    }

    public static function getNavigationLabel(): string
    {
        return __('Receive stock');
    }

    public function getTitle(): string
    {
        return __('Receive stock');
    }

    public function getSubheading(): ?string
    {
        return __('Opening stock and items that arrive without a purchase invoice. Scan each box or add lines by hand.');
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && app(BranchScopeResolver::class)->hasAny($user, 'stock.receive');
    }

    public function mount(): void
    {
        $this->form->fill([
            // The user's main branch when they may receive there, else the first allowed one.
            'branch_id' => $this->branchOptions()->has((int) Auth::user()?->primary_branch_id)
                ? Auth::user()->primary_branch_id
                : $this->branchOptions()->keys()->first(),
            'lines' => [],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('Where'))
                    ->columns(['default' => 1, 'md' => 3])
                    ->schema([
                        Select::make('branch_id')
                            ->label(__('Branch'))
                            ->options(fn () => $this->branchOptions())
                            ->required()
                            ->live()
                            ->native(false),
                        Select::make('supplier_id')
                            ->label(__('Supplier (optional)'))
                            ->options(fn () => Supplier::options())
                            ->searchable()
                            ->native(false),
                        TextInput::make('reason')
                            ->label(__('Note (optional)'))
                            ->placeholder(__('e.g. Opening stock, Duhok store room'))
                            ->maxLength(500),
                    ]),

                View::make('filament.stock.receive-scan'),

                Section::make(__('Items'))
                    ->description(fn (Get $get) => $this->summary($get('lines') ?? []))
                    ->schema([
                        Repeater::make('lines')
                            ->hiddenLabel()
                            ->addActionLabel(__('Add a line by hand'))
                            ->defaultItems(0)
                            ->reorderable(false)
                            ->collapsible(false)
                            ->columns(['default' => 2, 'md' => 4, 'xl' => 5])
                            ->itemLabel(fn (array $state) => $this->lineLabel($state))
                            ->schema([
                                Select::make('product_id')
                                    ->label(__('Product'))
                                    ->required()
                                    ->searchable()
                                    ->live()
                                    ->getSearchResultsUsing(fn (string $search) => Product::query()->active()->search($search)->limit(30)->get()->mapWithKeys(fn (Product $p) => [$p->id => $p->label()]))
                                    ->getOptionLabelUsing(fn ($value) => Product::query()->find($value)?->label())
                                    ->afterStateUpdated(function ($state, callable $set) {
                                        $product = Product::query()->find($state);
                                        $set('unit_cost', $product?->purchase_price);
                                        $set('cost_currency', $product->purchase_currency ?? 'USD');
                                        $set('quantity', 1);
                                    })
                                    ->columnSpan(['default' => 2, 'md' => 4, 'xl' => 2]),
                                // Serial-tracked: SN required. Lot items: the box's SN or LOT, whichever the label shows.
                                TextInput::make('serial')
                                    ->label(__('Serial').' (SN)')
                                    ->maxLength(64)
                                    ->required(fn (Get $get) => $this->isSerial($get('product_id')))
                                    ->placeholder(fn (Get $get) => $this->isSerial($get('product_id')) ? null : __('optional'))
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn ($state, callable $set) => filled($state) ? $set('quantity', 1) : null)
                                    ->extraInputAttributes(['dir' => 'ltr', 'class' => 'ot-code']),
                                TextInput::make('lot')->label(__('Lot').' (LOT)')->placeholder(__('optional'))->maxLength(64)->extraInputAttributes(['dir' => 'ltr', 'class' => 'ot-code']),
                                DatePicker::make('expiry_date')
                                    ->label(__('Expiry'))
                                    ->native(false)
                                    ->displayFormat('Y-m-d')
                                    ->required(fn (Get $get) => (bool) Product::query()->find($get('product_id'))?->requires_expiry),
                                TextInput::make('quantity')
                                    ->label(__('Qty'))
                                    ->numeric()
                                    ->integer()
                                    ->minValue(1)
                                    ->default(1)
                                    ->required()
                                    ->disabled(fn (Get $get) => $this->isSerial($get('product_id')) || filled($get('serial')))
                                    ->dehydrated(),
                                TextInput::make('unit_cost')->label(__('Unit cost'))->numeric()->minValue(0)->extraInputAttributes(['dir' => 'ltr']),
                                Select::make('cost_currency')->label(__('Currency'))->options(fn () => Currency::query()->active()->pluck('code', 'code'))->default('USD')->required()->native(false),
                                Select::make('location_id')
                                    ->label(__('Shelf'))
                                    ->options(fn (Get $get) => BranchLocation::query()->active()->where('branch_id', $get('../../branch_id'))->orderBy('code')->get()->mapWithKeys(fn (BranchLocation $l) => [$l->id => $l->label()]))
                                    ->native(false),
                            ]),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label(__('Add to stock'))->icon(Heroicon::OutlinedCheck)->submit('save'),
                        Action::make('clear')->label(__('Clear the list'))->color('gray')->requiresConfirmation()->action(fn () => $this->clearLines()),
                    ]),
                ]),
        ]);
    }

    /**
     * Scanner / camera / typed code → a prefilled line.
     */
    public function handleScan(?string $raw = null): void
    {
        $raw = DigitConverter::toWestern(trim($raw ?? $this->scan)) ?? '';
        $this->scan = '';

        if ($raw === '') {
            return;
        }

        /** @var User $user */
        $user = Auth::user();
        $result = app(ScanResolver::class)->resolve($raw, ScanContext::Receive, $user, (int) ($this->data['branch_id'] ?? 0) ?: null);

        foreach ($result->warnings as $warning) {
            Notification::make()->title($warning)->warning()->send();
        }

        match ($result->kind) {
            ScanResultKind::Unit => Notification::make()
                ->title(__('Serial :serial is already in the system (:status, :branch).', [
                    'serial' => $result->unit->serial,
                    'status' => $result->unit->status->getLabel(),
                    'branch' => $result->unit->branch->code,
                ]))
                ->danger()
                ->send(),
            ScanResultKind::NewUnit, ScanResultKind::Product, ScanResultKind::Lot => $this->addLine($result->product, $result->prefill()),
            ScanResultKind::LotSearch, ScanResultKind::Unknown => Notification::make()
                ->title(__('Code not recognised'))
                ->body(__('Pick the product by hand. The code was saved so this label format can be added.'))
                ->warning()
                ->send(),
        };
    }

    public function save(StockMovementService $stock): void
    {
        $state = $this->form->getState();
        /** @var User $user */
        $user = Auth::user();

        $branch = Branch::query()->findOrFail($state['branch_id']);
        $lines = array_map(fn (array $line) => ReceiveLine::fromArray($line + ['supplier_id' => $state['supplier_id'] ?? null]), array_values($state['lines'] ?? []));

        try {
            $result = $stock->receive($branch, $lines, $user, reason: $state['reason'] ?? null);
        } catch (StockRuleViolation $e) {
            Notification::make()->title($e->getMessage())->danger()->persistent()->send();

            return;
        }

        $notification = Notification::make()
            ->title(trans_choice(':count item added to :branch.|:count items added to :branch.', $result->quantity, ['count' => Display::number($result->quantity), 'branch' => $branch->code]))
            ->body(implode("\n", $result->warnings) ?: null)
            ->success();

        // Warnings (e.g. already expired) stay until read.
        if ($result->warnings !== []) {
            $notification->persistent();
        }

        $notification->send();

        $this->clearLines();
    }

    public function clearLines(): void
    {
        $this->data['lines'] = [];
    }

    /**
     * @param  array<string, mixed>  $prefill
     */
    private function addLine(?Product $product, array $prefill): void
    {
        if ($product === null) {
            return;
        }

        $lines = $this->data['lines'] ?? [];

        // Lot items: scanning the same lot again adds 1 to its line; a box with its own SN gets its own line.
        if (! $product->isSerialTracked() && blank($prefill['serial'])) {
            foreach ($lines as $key => $line) {
                if ((int) $line['product_id'] === $product->id
                    && blank($line['serial'] ?? null)
                    && ($line['lot'] ?? null) === $prefill['lot']
                    && ($line['expiry_date'] ?? null) === $prefill['expiry_date']) {
                    $lines[$key]['quantity'] = (int) $line['quantity'] + 1;
                    $this->data['lines'] = $lines;

                    return;
                }
            }
        } elseif (filled($prefill['serial'])) {
            foreach ($lines as $line) {
                if (($line['serial'] ?? null) === $prefill['serial']) {
                    Notification::make()->title(__('Serial :serial is already in the list.', ['serial' => $prefill['serial']]))->warning()->send();

                    return;
                }
            }
        }

        $lines[(string) Str::uuid()] = [
            'product_id' => $product->id,
            'serial' => $prefill['serial'],
            'lot' => $prefill['lot'],
            'expiry_date' => $prefill['expiry_date'],
            'quantity' => 1,
            'unit_cost' => $product->purchase_price,
            'cost_currency' => $product->purchase_currency ?? 'USD',
            'location_id' => null,
        ];

        $this->data['lines'] = $lines;

        if ($product->isSerialTracked() && blank($prefill['serial'])) {
            Notification::make()->title(__(':sku added — now type or scan its serial number.', ['sku' => $product->sku]))->info()->send();
        }
    }

    /**
     * @return Collection<int, string>
     */
    private function branchOptions()
    {
        /** @var User $user */
        $user = Auth::user();

        return app(BranchScopeResolver::class)->selectableBranches($user, 'stock.receive')->get()->mapWithKeys(fn (Branch $b) => [$b->id => $b->label()]);
    }

    private function isSerial(mixed $productId): bool
    {
        return filled($productId) && (bool) Product::query()->find($productId)?->isSerialTracked();
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function lineLabel(array $state): string
    {
        $product = filled($state['product_id'] ?? null) ? Product::query()->find($state['product_id']) : null;

        if ($product === null) {
            return __('New line');
        }

        $code = $state['serial'] ?? $state['lot'] ?? null;

        return $product->sku.($code ? ' · '.$code : '');
    }

    /**
     * @param  array<string, array<string, mixed>>  $lines
     */
    private function summary(array $lines): string
    {
        $quantity = array_sum(array_map(fn ($l) => (int) ($l['quantity'] ?? 1), $lines));

        return trans_choice(':count item in the list|:count items in the list', $quantity, ['count' => Display::number($quantity)]);
    }
}
