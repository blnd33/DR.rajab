<?php

namespace App\Filament\Admin\Resources\KitTypes\RelationManagers;

use App\Models\InstrumentType;
use App\Models\KitTemplateItem;
use App\Models\Product;
use App\Support\Locale\Display;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The template: which instruments and implant sizes (a size = a product) a kit should hold.
 */
class TemplateItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'templateItems';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Template');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            ToggleButtons::make('what')->label(__('What is expected?'))->inline()->live()->dehydrated(false)
                ->options(['instrument' => __('Instrument'), 'product' => __('Implant / product')])
                ->default('product')
                ->afterStateHydrated(fn (ToggleButtons $component, ?KitTemplateItem $record) => $component->state($record?->instrument_type_id !== null ? 'instrument' : 'product'))
                ->columnSpanFull(),
            Select::make('instrument_type_id')->label(__('Instrument type'))->options(fn () => InstrumentType::options())->native(false)
                ->visible(fn (Get $get) => $get('what') === 'instrument')->required(fn (Get $get) => $get('what') === 'instrument'),
            Select::make('product_id')->label(__('Product (size)'))->searchable()->native(false)
                ->getSearchResultsUsing(fn (string $search) => Product::query()->active()->search($search)->limit(30)->get()->mapWithKeys(fn (Product $p) => [$p->id => $p->label()]))
                ->getOptionLabelUsing(fn ($value) => Product::query()->find($value)?->label())
                ->visible(fn (Get $get) => $get('what') === 'product')->required(fn (Get $get) => $get('what') === 'product'),
            TextInput::make('expected_qty')->label(__('Expected quantity'))->numeric()->integer()->minValue(1)->default(1)->required(),
            TextInput::make('sort')->label(__('Order'))->numeric()->integer()->default(0),
            Toggle::make('is_required')->label(__('Required'))->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['product', 'instrumentType']))
            ->columns([
                TextColumn::make('item_kind')->label(__('Kind'))->badge(),
                TextColumn::make('label')->label(__('Expected'))->state(fn (KitTemplateItem $r) => $r->label())->wrap(),
                TextColumn::make('expected_qty')->label(__('Quantity'))->alignEnd()->formatStateUsing(fn ($state) => Display::number($state)),
                IconColumn::make('is_required')->label(__('Required'))->boolean(),
            ])
            ->headerActions([CreateAction::make()->mutateDataUsing(fn (array $data) => $this->clean($data))])
            ->recordActions([
                EditAction::make()->mutateDataUsing(fn (array $data) => $this->clean($data)),
                DeleteAction::make(),
            ])
            ->emptyStateHeading(__('No template lines yet'))
            ->emptyStateDescription(__('Add the instruments and implant sizes a full instrument set holds.'));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function clean(array $data): array
    {
        if (filled($data['product_id'] ?? null)) {
            $data['instrument_type_id'] = null;
        } else {
            $data['product_id'] = null;
        }

        return $data;
    }
}
