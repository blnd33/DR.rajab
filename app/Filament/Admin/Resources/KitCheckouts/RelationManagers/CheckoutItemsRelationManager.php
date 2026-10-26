<?php

namespace App\Filament\Admin\Resources\KitCheckouts\RelationManagers;

use App\Enums\CheckoutItemOutcome;
use App\Filament\Admin\Resources\Surgeries\SurgeryResource;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\KitCheckout;
use App\Models\KitCheckoutItem;
use App\Services\Assets\KitService;
use App\Support\Locale\Display;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

/**
 * What left with the kit and how each line was reconciled; unaccounted lines are settled here.
 */
class CheckoutItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Contents');
    }

    #[On('checkout-updated')]
    public function refreshRows(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        $qty = fn ($state) => Display::number($state);

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['instrument.type', 'stockUnit', 'stockBatch', 'product', 'surgeryItem.surgery']))
            ->defaultSort('id')
            ->columns([
                TextColumn::make('item_kind')->label(__('Kind'))->badge(),
                TextColumn::make('name')->label(__('Item'))->state(fn (KitCheckoutItem $r) => $r->name())->wrap(),
                TextColumn::make('code')->label(__('Code / serial / lot'))->state(fn (KitCheckoutItem $r) => $r->code())->fontFamily(FontFamily::Mono)->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('qty_out')->label(__('Out'))->alignEnd()->formatStateUsing($qty),
                TextColumn::make('qty_returned')->label(__('Back'))->alignEnd()->formatStateUsing($qty),
                TextColumn::make('qty_used')->label(__('Used'))->alignEnd()->formatStateUsing($qty)
                    ->url(fn (KitCheckoutItem $r) => $r->surgeryItem !== null ? SurgeryResource::getUrl('view', ['record' => $r->surgeryItem->surgery_id]) : null),
                TextColumn::make('qty_unaccounted')->label(__('Unaccounted'))->alignEnd()->formatStateUsing($qty)
                    ->color(fn (KitCheckoutItem $r) => $r->qty_unaccounted > 0 ? 'danger' : null),
                TextColumn::make('outcome')->label(__('Result'))->badge()->description(fn (KitCheckoutItem $r) => $r->note),
            ])
            ->filters([SelectFilter::make('outcome')->label(__('Result'))->options(CheckoutItemOutcome::options())])
            ->recordActions([
                Action::make('resolve')
                    ->label(__('Settle'))
                    ->icon(Heroicon::OutlinedCheckBadge)
                    ->color('warning')
                    ->visible(fn (KitCheckoutItem $r) => $r->outcome === CheckoutItemOutcome::Unaccounted && $r->qty_unaccounted > 0
                        && ServiceAction::user()->canInBranch('kits.resolve_unaccounted', $this->checkout()->branch_id))
                    ->schema([
                        Select::make('resolution')->label(__('What happened?'))->required()->native(false)->options([
                            'found' => __('Found — back in the kit'),
                            'used' => __('Used — the surgery is logged now'),
                            'lost' => __('Lost — write it off'),
                        ]),
                        Textarea::make('note')->label(__('Note'))->rows(2),
                    ])
                    ->action(fn (KitCheckoutItem $record, array $data, Action $action) => ServiceAction::run($action,
                        fn ($u) => app(KitService::class)->resolveUnaccounted($record, $data['resolution'], $u, $data['note'] ?? null), __('Line settled.'))),
            ])
            ->emptyStateHeading(__('The kit was empty'));
    }

    private function checkout(): KitCheckout
    {
        /** @var KitCheckout */
        return $this->getOwnerRecord();
    }
}
