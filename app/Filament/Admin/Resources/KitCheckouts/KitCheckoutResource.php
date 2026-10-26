<?php

namespace App\Filament\Admin\Resources\KitCheckouts;

use App\Enums\KitCheckoutStatus;
use App\Filament\Admin\Infolists\LocalDateTimeEntry;
use App\Filament\Admin\Resources\KitCheckouts\Pages\ListKitCheckouts;
use App\Filament\Admin\Resources\KitCheckouts\Pages\ViewKitCheckout;
use App\Filament\Admin\Resources\KitCheckouts\RelationManagers\CheckoutItemsRelationManager;
use App\Filament\Admin\Resources\Kits\KitResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Filament\Admin\Tables\Columns\LocalDateTimeColumn;
use App\Models\KitCheckout;
use App\Support\Locale\Display;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
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
 * Kits lent to hospitals: what left, how it came back, what is unaccounted (Module 18).
 */
class KitCheckoutResource extends Resource
{
    protected static ?string $model = KitCheckout::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?int $navigationSort = 35;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getNavigationGroup(): ?string
    {
        return __('Clinical');
    }

    public static function getModelLabel(): string
    {
        return __('Kit checkout');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Kit checkouts');
    }

    public static function getEloquentQuery(): Builder
    {
        return ServiceAction::scoped(parent::getEloquentQuery(), 'kits.view_any', ['branch_id']);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('status', KitCheckoutStatus::Out)->count();

        return $count > 0 ? Display::number($count) : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('Kits out at hospitals');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['kit.type', 'customer', 'doctor', 'branch']))
            ->defaultSort('checked_out_at', 'desc')
            ->columns([
                CodeColumn::make('number')->label(__('Number'))->searchable()->sortable(),
                TextColumn::make('kit.code')->label(__('Kit'))->description(fn (KitCheckout $r) => $r->kit->type->name),
                TextColumn::make('customer.code')->label(__('Hospital'))->badge()->color('gray'),
                TextColumn::make('doctor.name')->label(__('Doctor'))->placeholder('—')->toggleable(),
                LocalDateTimeColumn::make('checked_out_at')->label(__('Out'))->sortable(),
                LocalDateTimeColumn::make('due_back_at')->label(__('Due back'))->sortable()
                    ->color(fn (KitCheckout $r) => $r->isOverdue() ? 'danger' : null)
                    ->description(fn (KitCheckout $r) => $r->isOverdue() ? __('Overdue') : null),
                TextColumn::make('status')->label(__('Status'))->badge(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(KitCheckoutStatus::options()),
                SelectFilter::make('branch_id')->label(__('Branch'))->options(fn () => ServiceAction::branchOptions('kits.view_any')),
                Filter::make('overdue')->label(__('Overdue only'))
                    ->query(fn (Builder $query) => $query->where('status', KitCheckoutStatus::Out)->where('due_back_at', '<', now())),
            ])
            ->recordActions([ViewAction::make()])
            ->emptyStateHeading(__('No kit checkouts yet'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(['default' => 2, 'md' => 4])->schema([
                TextEntry::make('number')->label(__('Number'))->fontFamily(FontFamily::Mono)->extraAttributes(['dir' => 'ltr']),
                TextEntry::make('status')->label(__('Status'))->badge(),
                TextEntry::make('kit.code')->label(__('Kit'))->fontFamily(FontFamily::Mono)
                    ->url(fn (KitCheckout $r) => KitResource::getUrl('view', ['record' => $r->kit])),
                TextEntry::make('customer.name')->label(__('Hospital')),
                TextEntry::make('doctor.name')->label(__('Doctor'))->placeholder('—'),
                TextEntry::make('patient.label')->label(__('Patient'))->state(fn (KitCheckout $r) => $r->patient?->label())->placeholder('—'),
                TextEntry::make('planned_surgery_date')->label(__('Planned surgery date'))->formatStateUsing(fn ($state) => Display::date($state))->placeholder('—'),
                LocalDateTimeEntry::make('checked_out_at')->label(__('Out')),
                LocalDateTimeEntry::make('due_back_at')->label(__('Due back'))
                    ->color(fn (KitCheckout $r) => $r->isOverdue() ? 'danger' : null),
                LocalDateTimeEntry::make('returned_at')->label(__('Back'))->placeholder('—'),
                TextEntry::make('surgeries')->label(__('Surgeries with this kit'))
                    ->state(fn (KitCheckout $r) => $r->surgeries()->pluck('number')->implode(', ') ?: null)->placeholder('—')->fontFamily(FontFamily::Mono),
                TextEntry::make('notes')->label(__('Notes'))->placeholder('—')->columnSpanFull(),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [CheckoutItemsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKitCheckouts::route('/'),
            'view' => ViewKitCheckout::route('/{record}'),
        ];
    }
}
