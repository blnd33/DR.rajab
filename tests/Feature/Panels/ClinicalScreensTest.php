<?php

use App\Enums\Role;
use App\Models\Customer;
use App\Models\Instrument;
use App\Models\Kit;
use App\Models\KitCheckout;
use App\Models\KitType;
use App\Models\Patient;
use App\Models\Surgery;
use App\Services\Settings\SettingsRepository;
use Database\Seeders\DemoBranchSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\DemoClinicalSeeder;
use Database\Seeders\DemoMovementSeeder;
use Database\Seeders\InventoryBaseSeeder;

/*
 * Phase 3 screens open in every language with the demo kits, surgeries and patients;
 * patients stay private between doctors.
 */

beforeEach(function () {
    $this->seed([InventoryBaseSeeder::class, DemoBranchSeeder::class]);
    app(SettingsRepository::class)->set('security.require_admin_mfa', false);
    $this->admin = userWithRole(Role::SuperAdmin, [branch('DHK')]);
    $this->doctor = userWithRole(Role::Doctor, [branch('DHK')], ['username' => 'doctor']);
    $this->actingAs($this->admin);
    $this->seed([DemoCatalogSeeder::class, DemoMovementSeeder::class, DemoClinicalSeeder::class]);
});

it('opens every clinical admin screen', function (string $locale) {
    $this->admin->forceFill(['locale' => $locale])->save();
    $this->actingAs($this->admin->fresh());

    $paths = [
        '/admin',
        '/admin/surgeries',
        '/admin/surgeries/create',
        '/admin/patients',
        '/admin/patients/create',
        '/admin/kits',
        '/admin/kits/create',
        '/admin/instruments',
        '/admin/instruments/create',
        '/admin/kit-checkouts',
        '/admin/kit-types',
        '/admin/kit-types/create',
        '/admin/surgery-types',
        '/admin/instrument-types',
        '/admin/customers/'.Customer::query()->where('code', 'HSP-RIZGARY')->value('id'),
        '/admin/kit-types/'.KitType::query()->value('id').'/edit',
        '/admin/instruments/'.Instrument::query()->value('id').'/edit',
    ];

    foreach ([
        'surgeries' => Surgery::class,
        'patients' => Patient::class,
        'kits' => Kit::class,
        'instruments' => Instrument::class,
        'kit-checkouts' => KitCheckout::class,
    ] as $slug => $model) {
        foreach ($model::all() as $record) {
            $paths[] = "/admin/{$slug}/{$record->id}";
        }
    }

    foreach ($paths as $path) {
        expect($this->get($path)->status())->toBe(200, "{$path} in {$locale}");
    }
})->with(['en', 'ar', 'ckb']);

it('opens every clinical screen in the ops app', function (string $locale) {
    $this->doctor->forceFill(['locale' => $locale])->save();
    $this->actingAs($this->doctor->fresh());
    $staff = userWithRole(Role::BranchStaff, [branch('DHK')], ['locale' => $locale]);

    $paths = ['/app/tasks', '/app/surgeries', '/app/surgeries/new', '/app/lookup/patients?q=ka'];

    foreach (Surgery::all() as $surgery) {
        $paths[] = "/app/surgeries/{$surgery->id}";
        $paths[] = "/app/surgeries/new?surgery={$surgery->id}";
    }

    foreach ($paths as $path) {
        expect($this->get($path)->status())->toBe(200, "{$path} in {$locale}");
    }

    $this->actingAs($staff);

    foreach (['/app/kits', ...Kit::query()->where('branch_id', branch('DHK')->id)->get()->flatMap(fn ($k) => [
        "/app/kits/{$k->id}", "/app/kits/{$k->id}?mode=prepare", "/app/kits/{$k->id}?mode=checkout", "/app/kits/{$k->id}?mode=return",
    ])->all()] as $path) {
        expect($this->get($path)->status())->toBe(200, "{$path} in {$locale}");
    }
})->with(['en', 'ar', 'ckb']);

it('keeps patients and surgeries private between doctors', function () {
    $other = userWithRole(Role::Doctor, [branch('DHK')]);
    $this->actingAs($other);

    $patient = Patient::query()->firstOrFail();
    $surgery = Surgery::query()->firstOrFail();

    expect($this->get("/admin/patients/{$patient->id}")->status())->not->toBe(200)
        ->and($this->get("/app/surgeries/{$surgery->id}")->status())->toBe(403)
        ->and($this->getJson('/app/lookup/patients?q=Karwan')->json())->toBe([]);

    $this->actingAs($this->doctor);
    expect($this->getJson('/app/lookup/patients?q=Karwan')->json())->toHaveCount(1);
});

it('keeps kits of another branch away from branch staff', function () {
    $this->actingAs(userWithRole(Role::BranchStaff, [branch('BGW')]));

    expect($this->get('/app/kits/'.Kit::query()->where('branch_id', branch('DHK')->id)->value('id'))->status())->toBe(403);
});
