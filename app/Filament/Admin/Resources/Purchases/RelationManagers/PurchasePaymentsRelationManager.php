<?php

namespace App\Filament\Admin\Resources\Purchases\RelationManagers;

use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Models\Purchase;
use App\Models\SupplierPaymentAllocation;
use App\Support\Locale\Display;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

/**
 * Payments allocated to this invoice.
 */
class PurchasePaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'paymentAllocations';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Payments');
    }

    #[On('purchase-updated')]
    public function refreshRows(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        /** @var Purchase $purchase */
        $purchase = $this->getOwnerRecord();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('payment.cashBox.branch'))
            ->paginated(false)
            ->columns([
                CodeColumn::make('payment.number')->label(__('Payment')),
                TextColumn::make('paid_at')->label(__('Paid on'))->state(fn (SupplierPaymentAllocation $r) => Display::date($r->payment->paid_at)),
                TextColumn::make('amount_in_purchase_currency')->label(__('Amount'))->alignEnd()->weight('semibold')
                    ->formatStateUsing(fn ($state) => Display::money($state, $purchase->currency))
                    ->description(fn (SupplierPaymentAllocation $r) => $r->payment->currency !== $purchase->currency ? Display::money($r->amount_in_payment_currency, $r->payment->currency) : null),
                TextColumn::make('fx_gain_loss_usd')->label(__('FX gain / loss'))->alignEnd()->formatStateUsing(fn ($state) => Display::money($state, 'USD'))->toggleable(),
                TextColumn::make('payment.method')->label(__('Method'))->badge()->color('gray'),
                TextColumn::make('payment.status')->label(__('Status'))->badge(),
            ])
            ->emptyStateHeading(__('Not paid yet'));
    }
}
