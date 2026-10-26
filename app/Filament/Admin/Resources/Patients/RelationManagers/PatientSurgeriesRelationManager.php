<?php

namespace App\Filament\Admin\Resources\Patients\RelationManagers;

use App\Filament\Admin\Resources\Surgeries\SurgeryResource;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Models\Surgery;
use App\Support\Locale\Display;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Surgery history of a patient with the implants (serials) used — for follow-ups and recalls.
 */
class PatientSurgeriesRelationManager extends RelationManager
{
    protected static string $relationship = 'surgeries';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Surgeries and implants');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['type', 'customer', 'doctors', 'liveItems.stockUnit', 'liveItems.stockBatch', 'liveItems.product']))
            ->defaultSort('surgery_date', 'desc')
            ->columns([
                CodeColumn::make('number')->label(__('Number')),
                TextColumn::make('surgery_date')->label(__('Date'))->formatStateUsing(fn ($state) => Display::date($state)),
                TextColumn::make('type.name')->label(__('Surgery'))->description(fn (Surgery $r) => $r->laterality->getLabel()),
                TextColumn::make('customer.code')->label(__('Hospital'))->badge()->color('gray'),
                TextColumn::make('implants')->label(__('Implants used'))->html()->wrap()
                    ->state(fn (Surgery $r) => $r->liveItems->map(fn ($i) => e($i->product->sku).' <bdi dir="ltr" class="font-mono">'.e($i->code()).'</bdi>'.($i->quantity > 1 ? ' × '.Display::number($i->quantity) : ''))->implode('<br>')),
                TextColumn::make('status')->label(__('Status'))->badge(),
            ])
            ->recordUrl(fn (Surgery $r) => SurgeryResource::getUrl('view', ['record' => $r]))
            ->emptyStateHeading(__('No surgeries yet'));
    }
}
