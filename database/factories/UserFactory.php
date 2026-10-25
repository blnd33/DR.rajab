<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => Str::lower(Str::random(4)).'.'.fake()->unique()->numberBetween(1000, 99999),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+964750'.fake()->numerify('#######'),
            'password' => static::$password ??= Hash::make('Password-123'),
            'locale' => 'en',
            'all_branches' => false,
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function allBranches(): static
    {
        return $this->state(fn () => ['all_branches' => true]);
    }

    public function locale(string $locale): static
    {
        return $this->state(fn () => ['locale' => $locale]);
    }
}
