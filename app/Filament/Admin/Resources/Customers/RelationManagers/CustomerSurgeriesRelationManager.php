<?php

namespace App\Filament\Admin\Resources\Customers\RelationManagers;

use App\Filament\Admin\Resources\Surgeries\SurgeryResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Models\Surgery;
use App\Services\Clinical\SurgeryService;
use App\Support\Locale\Display;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Surgeries done at this hospital (only those the user may see).
 */
class CustomerSurgeriesRelationManager extends RelationManager
{
    protected static string $relationship = 'surgeries';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Surgeries');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return ServiceAction::user()->can('viewAny', Surgery::class);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('surgeries.id', app(SurgeryService::class)->visibleTo(ServiceAction::user())->select('surgeries.id'))->with(['type', 'doctors']))
            ->defaultSort('surgery_date', 'desc')
            ->columns([
                CodeColumn::make('number')->label(__('Number')),
                TextColumn::make('surgery_date')->label(__('Date'))->formatStateUsing(fn ($state) => Display::date($state)),
                TextColumn::make('type.name')->label(__('Surgery'))->placeholder('—'),
                TextColumn::make('lead')->label(__('Lead surgeon'))->state(fn (Surgery $r) => $r->leadDoctor()?->name),
                TextColumn::make('status')->label(__('Status'))->badge(),
            ])
            ->recordUrl(fn (Surgery $r) => SurgeryResource::getUrl('view', ['record' => $r]))
            ->emptyStateHeading(__('No surgeries yet'));
    }
}
