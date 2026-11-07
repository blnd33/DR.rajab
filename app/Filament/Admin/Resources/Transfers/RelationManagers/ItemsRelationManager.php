<?php

namespace App\Filament\Admin\Resources\Transfers\RelationManagers;

use App\Enums\LocationType;
use App\Enums\TransferLineResolution;
use App\Enums\TransferLineStatus;
use App\Enums\TransferStatus;
use App\Exceptions\Domain\StockRuleViolation;
use App\Filament\Admin\Support\ServiceAction;
use App\Filament\Admin\Tables\Columns\ExpiryColumn;
use App\Filament\Admin\Tables\Columns\ProductColumn;
use App\Models\StockBatch;
use App\Models\StockUnit;
use App\Models\Transfer;
use App\Models\TransferItem;
use App\Services\Movement\TransferService;
use App\Support\Locale\Display;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

/**
 * Lines of a transfer: add units / lots while it is a draft, settle differences after receiving.
 */
class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Items');
    }

    #[On('transfer-updated')]
    public function refreshItems(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['product', 'stockUnit', 'fromBatch']))
            ->columns([
                ProductColumn::make('product'),
                TextColumn::make('code')->label(__('Serial / lot'))->fontFamily(FontFamily::Mono)->extraAttributes(['dir' => 'ltr'])->state(fn (TransferItem $r) => $r->code()),
                ExpiryColumn::make('expiry')->state(fn (TransferItem $r) => ($r->stockUnit ?? $r->fromBatch)?->expiry_date)->sortable(false),
                TextColumn::make('quantity_sent')->label(__('Sent'))->alignEnd()->formatStateUsing(fn ($state) => Display::number($state)),
                TextColumn::make('quantity_received')->label(__('Received'))->alignEnd()->placeholder('—')->formatStateUsing(fn ($state) => Display::number($state)),
                TextColumn::make('line_status')->label(__('Result'))->badge(),
                TextColumn::make('resolution')->label(__('Settled'))->badge()->placeholder('—')
                    ->state(fn (TransferItem $r) => $r->line_status === TransferLineStatus::Received ? null : $r->resolution)
                    ->visible(fn () => in_array($this->transfer()->status, [TransferStatus::Received, TransferStatus::ReceivedWithDiscrepancy], true)),
            ])
            ->headerActions([
                Action::make('addUnit')
                    ->label(__('Add serial unit'))
                    ->icon(Heroicon::OutlinedFingerPrint)
                    ->visible(fn () => $this->isDraft())
                    ->schema([TextInput::make('serial')->label(__('Serial'))->required()->autofocus()->extraInputAttributes(['dir' => 'ltr', 'class' => 'ot-code', 'data-scan-field' => true])])
                    ->action(function (array $data, Action $action) {
                        $unit = StockUnit::query()->where('serial', trim($data['serial']))->first();

                        if ($unit === null) {
                            ServiceAction::run($action, fn () => throw new StockRuleViolation(__('Serial :serial is not in the system.', ['serial' => $data['serial']])));

                            return;
                        }

                        ServiceAction::run($action, fn ($u) => app(TransferService::class)->addUnit($this->transfer(), $unit, $u));
                    }),
                Action::make('addLot')
                    ->label(__('Add lot quantity'))
                    ->icon(Heroicon::OutlinedSquare3Stack3d)
                    ->visible(fn () => $this->isDraft())
                    ->schema([
                        Select::make('batch_id')
                            ->label(__('Lot'))
                            ->options(fn () => StockBatch::query()->with('product')
                                ->where('branch_id', $this->transfer()->from_branch_id)->where('location_type', LocationType::Shelf)->where('quantity', '>', 0)
                                ->orderBy('product_id')->orderBy('expiry_date')->get()
                                ->mapWithKeys(fn (StockBatch $b) => [$b->id => $b->product->sku.' · '.$b->codeLabel().' · '.Display::date($b->expiry_date).' · '.__(':qty on shelf', ['qty' => Display::number($b->quantity)])]))
                            ->searchable()->required()->native(false),
                        TextInput::make('quantity')->label(__('Quantity'))->numeric()->integer()->minValue(1)->required(),
                    ])
                    ->action(function (array $data, Action $action) {
                        ServiceAction::run($action, fn ($u) => app(TransferService::class)->addBatch($this->transfer(), StockBatch::query()->findOrFail($data['batch_id']), (int) $data['quantity'], $u));
                    }),
            ])
            ->recordActions([
                Action::make('remove')
                    ->label(__('Remove'))
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->visible(fn () => $this->isDraft())
                    ->requiresConfirmation()
                    ->action(fn (TransferItem $record, Action $action) => ServiceAction::run($action, fn ($u) => app(TransferService::class)->removeItem($record, $u))),
                Action::make('resolve')
                    ->label(__('Settle'))
                    ->icon(Heroicon::OutlinedCheckBadge)
                    ->color('warning')
                    ->visible(fn (TransferItem $record) => $record->resolution === TransferLineResolution::None
                        && in_array($record->line_status, [TransferLineStatus::Missing, TransferLineStatus::Short, TransferLineStatus::Extra], true)
                        && ServiceAction::user()->canInBranch('stock.adjust', $this->transfer()->to_branch_id))
                    ->schema(fn (TransferItem $record) => [
                        Select::make('resolution')
                            ->label(__('What happened?'))
                            ->options($record->line_status === TransferLineStatus::Extra
                                ? [TransferLineResolution::Found->value => __('Acknowledged (no stock change)')]
                                : collect([TransferLineResolution::Found, TransferLineResolution::ReturnedToSender, TransferLineResolution::WrittenOff])->mapWithKeys(fn ($r) => [$r->value => $r->getLabel()])->all())
                            ->required()->native(false),
                        Textarea::make('note')->label(__('Note'))->rows(2),
                    ])
                    ->action(function (TransferItem $record, array $data, Action $action) {
                        ServiceAction::run($action, fn ($u) => app(TransferService::class)->resolveLine($record, TransferLineResolution::from($data['resolution']), $u, $data['note'] ?? null), __('Line settled.'));
                    }),
            ])
            ->emptyStateHeading(__('No items yet'))
            ->emptyStateDescription(__('Add serial units or lot quantities, or scan them on the phone.'));
    }

    private function transfer(): Transfer
    {
        /** @var Transfer */
        return $this->getOwnerRecord();
    }

    private function isDraft(): bool
    {
        return $this->transfer()->status === TransferStatus::Draft
            && ServiceAction::user()->canInBranch('transfers.create', $this->transfer()->from_branch_id);
    }
}
