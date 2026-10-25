<?php

namespace App\Filament\Admin\Resources\Products\Pages;

use App\Filament\Admin\Actions\PrintLabelsAction;
use App\Filament\Admin\Resources\Products\ProductResource;
use App\Models\Product;
use App\Services\Catalog\ProductService;
use App\Services\Permissions\BranchScopeResolver;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Product $record */
        return app(ProductService::class)->update($record, $data);
    }

    protected function getHeaderActions(): array
    {
        return [
            PrintLabelsAction::make('product')
                ->visible(fn () => app(BranchScopeResolver::class)->hasAny(Auth::user(), 'products.print_labels')),
        ];
    }

    public function getTitle(): string
    {
        /** @var Product $record */
        $record = $this->getRecord();

        return $record->label();
    }
}
