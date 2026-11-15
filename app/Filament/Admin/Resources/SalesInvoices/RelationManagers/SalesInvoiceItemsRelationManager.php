<?php

namespace App\Filament\Admin\Resources\SalesInvoices\RelationManagers;

use App\Enums\InvoiceKind;
use App\Filament\Admin\Support\ServiceAction;
use App\Filament\Admin\Tables\Columns\ProductColumn;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Support\Locale\Display;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

class SalesInvoiceItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Items');
    }

    /**
     * A surgery invoice has no items: just its fee.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof SalesInvoice && $ownerRecord->kind === InvoiceKind::Sale;
    }

    #[On('invoice-updated')]
    public function refreshRows(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        /** @var SalesInvoice $invoice */
        $invoice = $this->getOwnerRecord();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['product', 'stockUnit', 'stockBatch']))
            ->paginated(false)
            ->columns([
                ProductColumn::make('product'),
                TextColumn::make('code')->label(__('Serial / lot'))->fontFamily(FontFamily::Mono)->extraAttributes(['dir' => 'ltr'])
                    ->state(fn (SalesInvoiceItem $r) => $r->stockUnit->serial ?? $r->stockBatch->lot ?? '—'),
                TextColumn::make('quantity')->label(__('Quantity'))->alignEnd()->formatStateUsing(fn ($state) => Display::number($state)),
                TextColumn::make('unit_price')->label(__('Price'))->alignEnd()->formatStateUsing(fn ($state) => Display::money($state, $invoice->currency)),
                TextColumn::make('line_total')->label(__('Line total'))->alignEnd()->weight('semibold')->formatStateUsing(fn ($state) => Display::money($state, $invoice->currency)),
                // Each piece's share of this sale: of what it cost us, and of what we received.
                TextColumn::make('cost_share')->label(__('Cost %'))->alignEnd()->placeholder('—')
                    ->formatStateUsing(fn ($state) => Display::number($state, 2).' %')->extraAttributes(['dir' => 'ltr'])
                    ->visible(fn () => ServiceAction::user()->can('reports.profit') || ServiceAction::user()->canInBranch('stock.view_cost', $invoice->branch_id)),
                TextColumn::make('price_share')->label(__('Received %'))->alignEnd()->placeholder('—')
                    ->formatStateUsing(fn ($state) => Display::number($state, 2).' %')->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('unit_cost')->label(__('Cost'))->alignEnd()->placeholder('—')->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn ($state, SalesInvoiceItem $r) => Display::money($state, $r->cost_currency)),
                TextColumn::make('override_reason')->label(__('Override reason'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ]);
    }
}
