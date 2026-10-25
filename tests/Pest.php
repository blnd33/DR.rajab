<?php

use App\Enums\Role;
use App\Models\Branch;
use App\Models\User;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test setup
|--------------------------------------------------------------------------
| Feature tests run against PostgreSQL (orthotrack_test) because the schema
| uses Postgres features (triggers, CHECK constraints, NULLS NOT DISTINCT).
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        $this->seed([CurrencySeeder::class, PermissionSeeder::class]);
    })
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * @param  list<Branch>  $branches
 */
function userWithRole(Role $role, array $branches = [], array $attributes = []): User
{
    $user = User::factory()->create($attributes + [
        'primary_branch_id' => $branches[0]->id ?? null,
    ]);

    $user->assignRole($role->value);

    if ($branches !== []) {
        $user->branches()->sync(array_map(fn (Branch $b) => $b->id, $branches));
    }

    return $user->fresh();
}

function branch(string $code = 'DHK', array $attributes = []): Branch
{
    return Branch::query()->where('code', $code)->first()
        ?? Branch::factory()->create(['code' => $code] + $attributes);
}
