<?php

namespace App\Filament\Admin\Resources\Technicians\RelationManagers;

use App\Enums\SurgeryStatus;
use App\Filament\Admin\Resources\Surgeries\SurgeryResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Models\Surgery;
use App\Models\SurgeryType;
use App\Services\Clinical\SurgeryService;
use App\Support\Locale\Display;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What a technician did: the surgeries they worked on (only those the viewer may see).
 */
class TechnicianSurgeriesRelationManager extends RelationManager
{
    protected static string $relationship = 'technicianSurgeries';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Surgeries');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['type', 'customer', 'branch', 'doctors'])
                ->whereIn('surgeries.id', app(SurgeryService::class)->visibleTo(ServiceAction::user())->select('surgeries.id')))
            ->defaultSort('surgery_date', 'desc')
            ->recordTitleAttribute('number')
            ->columns([
                CodeColumn::make('number')->label(__('Number'))->searchable(),
                TextColumn::make('surgery_date')->label(__('Date'))->sortable()->formatStateUsing(fn ($state) => Display::date($state)),
                TextColumn::make('type.name')->label(__('Surgery'))->placeholder('—'),
                TextColumn::make('customer.code')->label(__('Hospital'))->badge()->color('gray')->placeholder('—'),
                TextColumn::make('lead')->label(__('Lead surgeon'))->state(fn (Surgery $r) => $r->leadDoctor()?->name),
                TextColumn::make('fee_amount')->label(__('Surgery fee'))->alignEnd()->placeholder('—')
                    ->formatStateUsing(fn ($state, Surgery $r) => $state !== null ? Display::money($state, (string) $r->fee_currency) : null),
                TextColumn::make('status')->label(__('Status'))->badge(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(SurgeryStatus::options())->default(SurgeryStatus::Completed->value),
                SelectFilter::make('surgery_type_id')->label(__('Surgery'))->options(fn () => SurgeryType::options()),
            ])
            ->recordUrl(fn (Surgery $r) => SurgeryResource::getUrl('view', ['record' => $r]))
            ->emptyStateHeading(__('No surgeries yet'));
    }
}
