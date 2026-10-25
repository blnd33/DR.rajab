<?php

namespace App\Filament\Admin\Tables\Columns;

use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * The record's product: name + size (wraps), SKU underneath on one line (LTR, monospace).
 * Use for any table whose rows have a `product` relation.
 */
class ProductColumn extends TextColumn
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Product'))
            ->state(function (Model $record) {
                $product = $record->getRelationValue('product');

                return $product === null ? null : $product->name.(filled($product->size) ? ' · '.$product->size : '');
            })
            ->description(function (Model $record) {
                $sku = $record->getRelationValue('product')?->sku;

                return $sku === null ? null : new HtmlString('<bdi dir="ltr" class="ot-code whitespace-nowrap">'.e($sku).'</bdi>');
            })
            ->wrap();
    }
}
