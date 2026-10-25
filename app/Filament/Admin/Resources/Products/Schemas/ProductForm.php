<?php

namespace App\Filament\Admin\Resources\Products\Schemas;

use App\Enums\TrackingType;
use App\Filament\Admin\Forms\TranslatableInput;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Uom;
use App\Services\Catalog\ProductService;
use App\Support\Gs1\Gtin;
use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'lg' => 3])
            ->components([
                Section::make(__('Product'))
                    ->columnSpan(['lg' => 2])
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        TextInput::make('sku')
                            ->label(__('SKU / code'))
                            ->required()
                            ->maxLength(64)
                            ->unique(ignoreRecord: true)
                            ->extraInputAttributes(['dir' => 'ltr', 'class' => 'ot-code', 'data-scan-field' => true]),

                        TextInput::make('gtin')
                            ->label(__('GTIN'))
                            ->helperText(__('Optional. The 14-digit (01) number on GS1 box labels — scanning the box then finds this product.'))
                            ->maxLength(14)
                            ->unique(ignoreRecord: true)
                            ->rule(fn () => function (string $attribute, mixed $value, Closure $fail) {
                                if (filled($value) && ! Gtin::isValid((string) $value)) {
                                    $fail(__('This is not a valid GTIN (8, 12, 13 or 14 digits with a correct check digit).'));
                                }
                            })
                            ->extraInputAttributes(['dir' => 'ltr', 'class' => 'ot-code', 'inputmode' => 'numeric']),

                        TranslatableInput::make('name', __('Name'))->columnSpanFull(),
                        TranslatableInput::make('description', __('Description'), required: false, multiline: true)->columnSpanFull(),

                        Select::make('category_id')
                            ->label(__('Category'))
                            ->options(fn () => Category::options())
                            ->searchable()
                            ->native(false),

                        Select::make('brand_id')
                            ->label(__('Brand / manufacturer'))
                            ->options(fn () => Brand::query()->active()->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->native(false)
                            ->createOptionForm([
                                TextInput::make('name')->label(__('Name'))->required()->maxLength(120)->unique('brands', 'name'),
                                TextInput::make('country')->label(__('Country'))->maxLength(64),
                            ])
                            ->createOptionUsing(fn (array $data) => Brand::query()->create($data)->id),

                        TextInput::make('size')
                            ->label(__('Size'))
                            ->placeholder(__('e.g. Size 5 / 28 mm'))
                            ->maxLength(64),

                        Select::make('uom_id')
                            ->label(__('Unit'))
                            ->options(fn () => Uom::options())
                            ->default(fn () => Uom::query()->where('code', 'pc')->value('id'))
                            ->required()
                            ->native(false),
                    ]),

                Grid::make(1)
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        Section::make(__('Tracking'))
                            ->schema([
                                ToggleButtons::make('tracking_type')
                                    ->label(__('How is it counted?'))
                                    ->options(TrackingType::options())
                                    ->icons([
                                        TrackingType::Serial->value => TrackingType::Serial->getIcon(),
                                        TrackingType::Lot->value => TrackingType::Lot->getIcon(),
                                    ])
                                    ->default(TrackingType::Serial->value)
                                    ->required()
                                    ->helperText(fn (?Product $record) => $record !== null && app(ProductService::class)->hasStock($record)
                                        ? __('Locked: this product already has stock.')
                                        : __('Serial: every unit has its own serial number (implants). Lot: counted by quantity per lot (screws, cement).'))
                                    ->disabled(fn (?Product $record) => $record !== null && app(ProductService::class)->hasStock($record)),
                                Toggle::make('is_implant')->label(__('Implant'))->default(true),
                                Toggle::make('requires_expiry')->label(__('Has an expiry date'))->default(true),
                                Toggle::make('is_active')->label(__('Active'))->default(true)
                                    ->helperText(__('Inactive products are hidden from daily lists; their history stays.')),
                            ]),

                        Section::make(__('Prices and minimum'))
                            ->columns(2)
                            ->schema([
                                TextInput::make('purchase_price')->label(__('Purchase price'))->numeric()->minValue(0)->extraInputAttributes(['dir' => 'ltr']),
                                Select::make('purchase_currency')->label(__('Currency'))->options(fn () => Currency::options())->default('USD')->native(false)
                                    ->requiredWith('purchase_price'),
                                TextInput::make('selling_price')->label(__('Selling price'))->numeric()->minValue(0)->extraInputAttributes(['dir' => 'ltr']),
                                Select::make('selling_currency')->label(__('Currency'))->options(fn () => Currency::options())->default('USD')->native(false)
                                    ->requiredWith('selling_price'),
                                TextInput::make('min_stock_default')
                                    ->label(__('Minimum stock (default per branch)'))
                                    ->helperText(__('Below this on the shelf → low-stock alert. Each branch can override it.'))
                                    ->numeric()->integer()->minValue(0)->default(0)->required()
                                    ->columnSpanFull(),
                            ]),

                        Section::make(__('Photo'))
                            ->schema([
                                FileUpload::make('photo_path')
                                    ->hiddenLabel()
                                    ->image()
                                    ->imageEditor()
                                    ->disk('public')
                                    ->directory('products')
                                    ->maxSize(2048),
                            ]),
                    ]),
            ]);
    }
}
