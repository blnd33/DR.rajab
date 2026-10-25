<?php

namespace Database\Factories;

use App\Enums\BranchStatus;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    public function definition(): array
    {
        $city = fake()->unique()->city();

        return [
            'code' => Str::upper(Str::random(2)).fake()->unique()->numberBetween(10, 999),
            'name' => ['en' => $city],
            'city' => ['en' => $city],
            'default_currency' => 'USD',
            'is_head_office' => false,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Branch $branch) {
            $branch->status ??= BranchStatus::Active;
        });
    }

    public function closed(): static
    {
        return $this->afterMaking(function (Branch $branch) {
            $branch->status = BranchStatus::Closed;
            $branch->close_reason = 'Test';
        });
    }
}
