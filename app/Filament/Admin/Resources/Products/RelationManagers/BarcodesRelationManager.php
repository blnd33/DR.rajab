<?php

namespace App\Filament\Admin\Resources\Products\RelationManagers;

use App\Enums\BarcodeSymbology;
use App\Filament\Admin\Tables\Columns\CodeColumn;
use App\Filament\Admin\Tables\Columns\LocalDateTimeColumn;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Services\Catalog\ProductService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Extra codes that find this product when scanned (supplier codes, EANs, old labels).
 */
class BarcodesRelationManager extends RelationManager
{
    protected static string $relationship = 'barcodes';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Extra barcodes');
    }

    public function isReadOnly(): bool
    {
        return ! (Auth::user()?->can('products.manage') ?? false);
    }

    public function table(Table $table): Table
    {
        return $table
            ->description(__('Any of these codes finds this product when scanned, besides its SKU and GTIN.'))
            ->columns([
                CodeColumn::make('barcode')->label(__('Barcode')),
                TextColumn::make('symbology')->label(__('Type'))->badge()->color('gray'),
                LocalDateTimeColumn::make('created_at')->label(__('Added')),
            ])
            ->headerActions([
                Action::make('add')
                    ->label(__('Add barcode'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->schema([
                        TextInput::make('barcode')
                            ->label(__('Barcode'))
                            ->helperText(__('Scan it with the barcode scanner or type it.'))
                            ->required()
                            ->maxLength(128)
                            ->autofocus()
                            ->extraInputAttributes(['dir' => 'ltr', 'class' => 'ot-code', 'data-scan-field' => true]),
                        Select::make('symbology')
                            ->label(__('Type'))
                            ->options(BarcodeSymbology::options())
                            ->default(BarcodeSymbology::Supplier->value)
                            ->required()
                            ->native(false),
                    ])
                    ->action(function (array $data) {
                        /** @var Product $product */
                        $product = $this->getOwnerRecord();
                        app(ProductService::class)->addBarcode($product, $data['barcode'], BarcodeSymbology::from($data['symbology']));

                        Notification::make()->title(__('Barcode added.'))->success()->send();
                    }),
            ])
            ->recordActions([
                // Extra barcodes are lookup aids, not stock history: removing one is safe.
                DeleteAction::make()
                    ->label(__('Remove'))
                    ->authorize(fn () => Auth::user()?->can('products.manage'))
                    ->using(fn (ProductBarcode $record) => $record->delete()),
            ])
            ->emptyStateHeading(__('No extra barcodes'))
            ->emptyStateDescription(__('Add the supplier\'s codes so scanning their boxes finds this product.'));
    }
}
