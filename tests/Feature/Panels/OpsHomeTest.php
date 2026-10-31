<?php

use App\Enums\Role;
use App\Services\Clinical\SurgeryService;
use Illuminate\Support\Str;

/*
 * The phone app's home: a short clinical home for whoever can log surgeries, the stock home
 * for everyone else.
 */

it('gives the doctor a short home with New surgery and the drafts to finish', function () {
    $doctor = userWithRole(Role::Doctor, [branch('DHK')]);
    app(SurgeryService::class)->sync(['client_uuid' => (string) Str::uuid(), 'branch_id' => branch('DHK')->id, 'items' => [], 'complete' => false], $doctor);

    $this->actingAs($doctor)->get('/app')->assertOk()
        ->assertSee(route('ops.surgeries.create'))
        ->assertSee('My surgeries')
        ->assertSee('Drafts: 1')
        ->assertDontSee('Stock in this branch')
        ->assertDontSee('Quick actions');
});

it('keeps the stock home for branch staff', function () {
    $staff = userWithRole(Role::BranchStaff, [branch('DHK')]);

    $this->actingAs($staff)->get('/app')->assertOk()
        ->assertSee('Scan anything')
        ->assertSee('Stock in this branch')
        ->assertDontSee(route('ops.surgeries.create'));
});
