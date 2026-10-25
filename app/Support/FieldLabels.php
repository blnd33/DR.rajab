<?php

namespace App\Support;

/**
 * Human labels for field keys shown in old → new diffs (ledger corrections, import preview).
 */
final class FieldLabels
{
    public static function for(string $field): string
    {
        return match ($field) {
            'sku' => __('SKU'),
            'gtin' => __('GTIN'),
            'name_en' => __('Name EN'),
            'name_ar' => __('Name AR'),
            'name_ckb' => __('Name KU'),
            'category' => __('Category'),
            'brand' => __('Brand'),
            'size' => __('Size'),
            'unit' => __('Unit'),
            'tracking_type' => __('Tracking type'),
            'purchase_price', 'base_unit_cost' => __('Purchase price'),
            'purchase_currency' => __('Purchase currency'),
            'selling_price' => __('Selling price'),
            'selling_currency' => __('Selling currency'),
            'currency', 'cost_currency' => __('Currency'),
            'min_stock' => __('Min stock'),
            'max_stock' => __('Max stock'),
            'reorder_qty' => __('Reorder quantity'),
            'lot' => __('Lot'),
            'serial' => __('Serial'),
            'expiry_date' => __('Expiry date'),
            'production_date' => __('Production date'),
            'supplier_id' => __('Supplier'),
            'branch_location_id' => __('Shelf'),
            'quantity' => __('Quantity'),
            'branch' => __('Branch'),
            default => $field,
        };
    }
}
