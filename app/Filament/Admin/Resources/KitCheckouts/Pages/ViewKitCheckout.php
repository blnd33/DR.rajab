<?php

namespace App\Filament\Admin\Resources\KitCheckouts\Pages;

use App\Enums\CheckoutItemKind;
use App\Enums\KitCheckoutStatus;
use App\Enums\StockStatus;
use App\Filament\Admin\Resources\KitCheckouts\KitCheckoutResource;
use App\Filament\Admin\Support\PrintAction;
use App\Filament\Admin\Support\RefreshesInfolist;
use App\Filament\Admin\Support\ServiceAction;
use App\Models\KitCheckout;
use App\Models\KitCheckoutItem;
use App\Services\Assets\KitService;
use App\Support\Locale\Display;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;

class ViewKitCheckout extends ViewRecord
{
    use RefreshesInfolist;

    protected static string $resource = KitCheckoutResource::class;

    public function getTitle(): string
    {
        return __('Kit checkout :number', ['number' => $this->checkout()->number]);
    }

    protected function getHeaderActions(): array
    {
        return [
            PrintAction::make('kit-checkout', fn () => $this->checkout()),
            Action::make('return')
                ->label(__('Return the kit'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('success')
                ->visible(fn () => $this->checkout()->status === KitCheckoutStatus::Out && ServiceAction::user()->canInBranch('kits.return', $this->checkout()->branch_id))
                ->modalWidth('3xl')
                ->modalDescription(__('Tick what came back. Implants used in a logged surgery are recognised by themselves; anything else not back becomes an issue for the Super Admin.'))
                ->fillForm(fn () => $this->returnDefaults())
                ->schema(fn () => $this->returnForm())
                ->action(function (array $data, Action $action) {
                    $instruments = [];

                    foreach ((array) ($data['instruments'] ?? []) as $id => $result) {
                        $instruments[(int) $id] = (string) $result;
                    }

                    ServiceAction::run($action, fn ($u) => app(KitService::class)->return(
                        $this->checkout(),
                        $instruments,
                        array_map('intval', (array) ($data['units'] ?? [])),
                        array_map('intval', (array) ($data['lots'] ?? [])),
                        $u,
                        $data['notes'] ?? null,
                    ), __('Kit returned and reconciled.'));

                    $this->record = $this->checkout()->fresh();
                    $this->dispatch('checkout-updated');
                }),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function returnDefaults(): array
    {
        $items = $this->checkout()->items()->with('stockUnit')->get();

        return [
            'instruments' => $items->where('item_kind', CheckoutItemKind::Instrument)->mapWithKeys(fn (KitCheckoutItem $i) => [$i->instrument_id => 'returned'])->all(),
            'units' => [],
            'lots' => $items->where('item_kind', CheckoutItemKind::Batch)->mapWithKeys(fn (KitCheckoutItem $i) => [$i->id => $i->stockBatch->quantity ?? 0])->all(),
        ];
    }

    /**
     * @return list<mixed>
     */
    private function returnForm(): array
    {
        $items = $this->checkout()->items()->with(['instrument.type', 'stockUnit', 'stockBatch', 'product'])->get();
        $instruments = $items->where('item_kind', CheckoutItemKind::Instrument);
        $units = $items->where('item_kind', CheckoutItemKind::Unit)->filter(fn (KitCheckoutItem $i) => $i->stockUnit->status === StockStatus::OutAtHospital);
        $lots = $items->where('item_kind', CheckoutItemKind::Batch);
        $used = $items->where('item_kind', CheckoutItemKind::Unit)->count() - $units->count();

        return array_values(array_filter([
            $instruments->isEmpty() ? null : Section::make(__('Instruments'))->columns(2)->schema(
                $instruments->map(fn (KitCheckoutItem $i) => Select::make("instruments.{$i->instrument_id}")
                    ->label($i->instrument->code.' · '.$i->instrument->type->name)
                    ->options(['returned' => __('Returned'), 'missing' => __('Missing'), 'damaged' => __('Damaged')])
                    ->required()->native(false))->values()->all()
            ),
            $units->isEmpty() ? null : Section::make(__('Implants that came back'))->schema([
                CheckboxList::make('units')->hiddenLabel()->columns(2)->bulkToggleable()
                    ->options($units->mapWithKeys(fn (KitCheckoutItem $i) => [$i->stock_unit_id => $i->stockUnit->serial.' · '.$i->name()])->all()),
                Text::make(trans_choice(':count implant was used in a surgery and is not listed.|:count implants were used in surgeries and are not listed.', $used))->visible($used > 0),
            ]),
            $lots->isEmpty() ? null : Section::make(__('Lot quantities that came back'))->columns(2)->schema(
                $lots->map(fn (KitCheckoutItem $i) => TextInput::make("lots.{$i->id}")
                    ->label(($i->stockBatch->lot ?? '—').' · '.$i->name())
                    ->helperText(__('Out: :out · still at the hospital: :left', ['out' => Display::number($i->qty_out), 'left' => Display::number($i->stockBatch->quantity ?? 0)]))
                    ->numeric()->integer()->minValue(0)->maxValue($i->stockBatch->quantity ?? 0)->required())->values()->all()
            ),
            Textarea::make('notes')->label(__('Notes'))->rows(2),
        ]));
    }

    private function checkout(): KitCheckout
    {
        /** @var KitCheckout */
        return $this->getRecord();
    }
}
