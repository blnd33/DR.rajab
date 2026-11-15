<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Resources\SalesInvoices\SalesInvoiceResource;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Sales\InvoiceDescriber;
use App\Support\Locale\Display;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Facades\Auth;

/**
 * Who owes us: every invoice still waiting for money — who owes it, what it is for, the
 * doctor, the amount and how long it waits. Mark it paid from here.
 */
class UnpaidInvoices extends TableWidget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('dashboard.view') && $user->can('viewAny', SalesInvoice::class);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('Who owes us'))
            ->description(__('Invoices not paid yet, oldest first.'))
            ->query(fn () => InvoiceDescriber::open(SalesInvoiceResource::getEloquentQuery())->with(InvoiceDescriber::WITH))
            ->defaultSort('issued_at')
            ->columns([
                CodeColumn::make('number')->label(__('Number')),
                TextColumn::make('buyer')->label(__('Who owes'))->weight('semibold')->state(fn (SalesInvoice $r) => InvoiceDescriber::buyer($r))->wrap(),
                TextColumn::make('for')->label(__('For'))->state(fn (SalesInvoice $r) => InvoiceDescriber::forWhat($r))->wrap(),
                TextColumn::make('doctor')->label(__('Doctor'))->state(fn (SalesInvoice $r) => InvoiceDescriber::doctor($r)),
                TextColumn::make('owed')->label(__('Owed'))->alignEnd()->weight('semibold')
                    ->state(fn (SalesInvoice $r) => Display::money(InvoiceDescriber::owed($r), $r->currency)),
                TextColumn::make('issued_at')->label(__('Waiting'))->alignEnd()->sortable()
                    ->formatStateUsing(fn ($state) => trans_choice(':count day|:count days', (int) now()->diffInDays($state, true), ['count' => Display::number((int) now()->diffInDays($state, true))])),
            ])
            ->recordUrl(fn (SalesInvoice $r) => SalesInvoiceResource::getUrl('view', ['record' => $r]))
            ->recordActions([SalesInvoiceResource::markPaidAction()])
            ->paginated([10, 25, 50])
            ->emptyStateHeading(__('Everything is paid.'));
    }
}
