<?php

namespace App\Filament\Admin\Resources\Suppliers\RelationManagers;

use App\Filament\Admin\Resources\Purchases\PurchaseResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Models\Purchase;
use App\Services\Purchasing\PurchaseService;
use App\Support\Locale\Display;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SupplierPurchasesRelationManager extends RelationManager
{
    protected static string $relationship = 'purchases';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Purchases');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return ServiceAction::user()->can('viewAny', Purchase::class);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => ServiceAction::scoped($query->with('branch'), 'purchases.view_any', ['branch_id']))
            ->defaultSort('invoice_date', 'desc')
            ->columns([
                CodeColumn::make('number')->label(__('Number')),
                CodeColumn::make('supplier_invoice_number')->label(__('Supplier invoice no.')),
                TextColumn::make('branch.code')->label(__('Branch'))->badge()->color('gray'),
                TextColumn::make('invoice_date')->label(__('Invoice date'))->formatStateUsing(fn ($state) => Display::date($state)),
                TextColumn::make('due_date')->label(__('Due'))->placeholder('—')->formatStateUsing(fn ($state) => Display::date($state))
                    ->color(fn (Purchase $r) => PurchaseResource::isOverdue($r) ? 'danger' : null),
                TextColumn::make('total')->label(__('Total'))->alignEnd()->formatStateUsing(fn ($state, Purchase $r) => Display::money($state, $r->currency)),
                TextColumn::make('open')->label(__('Open'))->alignEnd()->state(fn (Purchase $r) => Display::money(app(PurchaseService::class)->open($r), $r->currency)),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('payment_status')->label(__('Paid'))->badge(),
            ])
            ->recordUrl(fn (Purchase $r) => PurchaseResource::getUrl('view', ['record' => $r]))
            ->emptyStateHeading(__('No purchases yet'));
    }
}
