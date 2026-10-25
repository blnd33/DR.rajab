<?php

namespace Database\Factories;

use App\Enums\TrackingType;
use App\Models\Product;
use App\Models\Uom;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'sku' => 'SKU-'.fake()->unique()->numerify('#####'),
            'name' => ['en' => fake()->words(3, true)],
            'uom_id' => fn () => Uom::query()->firstOrCreate(['code' => 'pc'], ['name' => ['en' => 'Piece']])->id,
            'tracking_type' => TrackingType::Serial,
            'is_implant' => true,
            'requires_expiry' => true,
            'purchase_price' => '100.0000',
            'purchase_currency' => 'USD',
            'selling_price' => '150.0000',
            'selling_currency' => 'USD',
            'min_stock_default' => 0,
            'is_active' => true,
        ];
    }

    public function lot(): static
    {
        return $this->state(['tracking_type' => TrackingType::Lot, 'is_implant' => false]);
    }

    public function gtin(string $gtin): static
    {
        return $this->state(['gtin' => $gtin]);
    }
}
