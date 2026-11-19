<?php

namespace App\Filament\Admin\Resources\LabelTemplates;

use App\Enums\LabelSymbology;
use App\Filament\Admin\Resources\LabelTemplates\Pages\ManageLabelTemplates;
use App\Models\LabelTemplate;
use App\Services\Labels\LabelRenderer;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/**
 * Thermal label layouts (Module 5): size in mm, barcode type, printed fields.
 */
class LabelTemplateResource extends Resource
{
    protected static ?string $model = LabelTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPrinter;

    protected static ?int $navigationSort = 82;

    public static function getNavigationGroup(): ?string
    {
        return __('System');
    }

    public static function getModelLabel(): string
    {
        return __('Label template');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Label templates');
    }

    public static function fieldOptions(): array
    {
        return [
            'name' => __('Product name'),
            'sku' => __('SKU'),
            'size' => __('Size'),
            'serial' => __('Serial'),
            'lot' => __('Lot'),
            'expiry' => __('Expiry date'),
            'branch' => __('Station'),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label(__('Name'))->required()->maxLength(120)->columnSpanFull(),
            TextInput::make('width_mm')->label(__('Width (mm)'))->numeric()->minValue(10)->maxValue(200)->required()->live(onBlur: true),
            TextInput::make('height_mm')->label(__('Height (mm)'))->numeric()->minValue(10)->maxValue(200)->required()->live(onBlur: true),
            Select::make('symbology')
                ->label(__('Barcode type'))
                ->options(LabelSymbology::options())
                ->helperText(__('GS1 DataMatrix needs a product GTIN; without it the label uses a plain DataMatrix.'))
                ->required()
                ->live()
                ->native(false),
            TextInput::make('layout.font_pt')->label(__('Text size (pt)'))->numeric()->minValue(4)->maxValue(16)->default(7)->required(),
            CheckboxList::make('layout.fields')
                ->label(__('Printed text'))
                ->options(self::fieldOptions())
                ->default(['name', 'sku', 'serial', 'lot', 'expiry'])
                ->columns(2)
                ->columnSpanFull(),
            Toggle::make('is_default')->label(__('Default template')),
            Placeholder::make('preview')
                ->label(__('Barcode preview'))
                ->content(function (Get $get) {
                    $symbology = LabelSymbology::tryFrom((string) $get('symbology')) ?? LabelSymbology::Code128;
                    $payload = $symbology === LabelSymbology::Gs1DataMatrix ? "01087123456789061727013110L2401\x1D21SN-000123" : 'SN-000123';
                    $svg = app(LabelRenderer::class)->svg($payload, $symbology);

                    return new HtmlString('<div style="width:'.($symbology->isTwoDimensional() ? '80px' : '220px').';height:80px">'.$svg.'</div>');
                })
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable(),
                TextColumn::make('size')->label(__('Size'))->state(fn (LabelTemplate $record) => $record->sizeLabel())->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('symbology')->label(__('Barcode type'))->badge()->color('gray'),
                IconColumn::make('is_default')
                    ->label(__('Default'))
                    ->icon(fn (bool $state) => $state ? Heroicon::Star : null)
                    ->color('warning'),
            ])
            ->recordActions([EditAction::make()])
            ->headerActions([CreateAction::make()])
            ->emptyStateHeading(__('No label templates yet'));
    }

    public static function getPages(): array
    {
        return ['index' => ManageLabelTemplates::route('/')];
    }
}
