<?php

namespace App\Filament\Admin\Resources\Surgeries;

use App\Enums\SurgeryDoctorRole;
use App\Enums\SurgeryStatus;
use App\Filament\Admin\Infolists\LocalDateTimeEntry;
use App\Filament\Admin\Resources\Patients\PatientResource;
use App\Filament\Admin\Resources\Surgeries\Pages\CreateSurgery;
use App\Filament\Admin\Resources\Surgeries\Pages\ListSurgeries;
use App\Filament\Admin\Resources\Surgeries\Pages\ViewSurgery;
use App\Filament\Admin\Resources\Surgeries\RelationManagers\SurgeryItemsRelationManager;
use App\Filament\Admin\Support\ServiceAction;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Models\Surgery;
use App\Models\SurgeryType;
use App\Services\Clinical\SurgeryService;
use App\Support\Locale\Display;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Logged surgeries (Module 10): what was implanted, in whom, where and by whom.
 */
class SurgeryResource extends Resource
{
    protected static ?string $model = Surgery::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getNavigationGroup(): ?string
    {
        return __('Clinical');
    }

    public static function getModelLabel(): string
    {
        return __('Surgery');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Surgeries');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('id', app(SurgeryService::class)->visibleTo(ServiceAction::user())->select('surgeries.id'));
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('status', SurgeryStatus::Draft)->count();

        return $count > 0 ? Display::number($count) : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('Drafts');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['type', 'patient', 'customer', 'branch', 'doctors'])->withCount('liveItems'))
            ->defaultSort('surgery_date', 'desc')
            ->columns([
                CodeColumn::make('number')->label(__('Number'))->searchable()->sortable(),
                TextColumn::make('surgery_date')->label(__('Date'))->sortable()->formatStateUsing(fn ($state) => Display::date($state)),
                TextColumn::make('type.name')->label(__('Surgery'))->description(fn (Surgery $r) => $r->laterality->getLabel())->placeholder('—'),
                TextColumn::make('patient.full_name')->label(__('Patient'))->placeholder('—')->state(fn (Surgery $r) => $r->patientName())
                    ->description(fn (Surgery $r) => $r->patient?->file_number),
                TextColumn::make('customer.code')->label(__('Hospital'))->badge()->color('gray')->placeholder('—'),
                TextColumn::make('lead')->label(__('Lead surgeon'))->state(fn (Surgery $r) => $r->leadDoctor()?->name)->toggleable(),
                TextColumn::make('branch.code')->label(__('Branch'))->badge()->color('gray')->toggleable(),
                TextColumn::make('live_items_count')->label(__('Items'))->alignEnd()->formatStateUsing(fn ($state) => Display::number($state)),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->description(fn (Surgery $r) => $r->problems !== null ? trans_choice(':count problem|:count problems', count($r->problems)) : null),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(SurgeryStatus::options()),
                SelectFilter::make('surgery_type_id')->label(__('Surgery'))->options(fn () => SurgeryType::options()),
                SelectFilter::make('branch_id')->label(__('Branch'))->options(fn () => ServiceAction::branchOptions('surgeries.view_any')),
                Filter::make('this_month')->label(__('This month'))
                    ->query(fn (Builder $query) => $query->where('surgery_date', '>=', now()->startOfMonth()->toDateString())),
            ])
            ->recordActions([ViewAction::make()])
            ->emptyStateHeading(__('No surgeries yet'))
            ->emptyStateDescription(__('Doctors log surgeries on the phone; you can also add one here.'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(['default' => 2, 'md' => 4])->schema([
                TextEntry::make('number')->label(__('Number'))->fontFamily(FontFamily::Mono)->extraAttributes(['dir' => 'ltr']),
                TextEntry::make('status')->label(__('Status'))->badge(),
                TextEntry::make('surgery_date')->label(__('Date'))->formatStateUsing(fn ($state) => Display::date($state)),
                TextEntry::make('type.name')->label(__('Surgery'))->placeholder('—'),
                TextEntry::make('patient.full_name')->label(__('Patient'))->placeholder('—')->state(fn (Surgery $r) => $r->patientName())
                    ->url(fn (Surgery $r) => $r->patient !== null ? PatientResource::getUrl('view', ['record' => $r->patient]) : null),
                TextEntry::make('customer.name')->label(__('Hospital'))->placeholder('—'),
                TextEntry::make('laterality')->label(__('Side'))->badge(),
                TextEntry::make('branch.code')->label(__('Branch')),
                TextEntry::make('doctors_list')->label(__('Doctors'))
                    ->state(fn (Surgery $r) => $r->doctors->reject(fn ($d) => $d->getRelationValue('pivot')?->getAttribute('role') === SurgeryDoctorRole::Technician->value)
                        ->map(fn ($d) => $d->name.($d->getRelationValue('pivot')?->getAttribute('role') === SurgeryDoctorRole::Assistant->value ? ' ('.__('Assistant').')' : ''))->implode(', ')),
                TextEntry::make('technicians_list')->label(__('Technicians'))->placeholder('—')
                    ->state(fn (Surgery $r) => $r->technicians()->pluck('name')->implode(', ') ?: null),
                TextEntry::make('fee_amount')->label(__('Surgery fee'))->placeholder('—')
                    ->formatStateUsing(fn ($state, Surgery $r) => $state !== null ? Display::money($state, (string) $r->fee_currency) : null),
                TextEntry::make('billing_status')->label(__('Invoice'))->badge(),
                TextEntry::make('kitCheckout.number')->label(__('Kit checkout'))->fontFamily(FontFamily::Mono)->placeholder('—'),
                TextEntry::make('creator.name')->label(__('Logged by')),
                LocalDateTimeEntry::make('completed_at')->label(__('Completed'))->placeholder('—'),
                TextEntry::make('notes')->label(__('Notes'))->placeholder('—')->columnSpanFull(),
                TextEntry::make('problems')->label(__('Why it is still a draft'))->listWithLineBreaks()->bulleted()->color('danger')->columnSpanFull()
                    ->visible(fn (Surgery $r) => $r->problems !== null),
                TextEntry::make('cancel_reason')->label(__('Cancel reason'))->columnSpanFull()->visible(fn (Surgery $r) => $r->cancel_reason !== null),
                ViewEntry::make('photos')->label(__('Photos'))->view('filament.attachments.gallery')->columnSpanFull()
                    ->visible(fn (Surgery $r) => $r->attachments()->exists()),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [SurgeryItemsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSurgeries::route('/'),
            'create' => CreateSurgery::route('/create'),
            'view' => ViewSurgery::route('/{record}'),
        ];
    }
}
