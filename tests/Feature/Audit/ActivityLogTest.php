<?php

use App\Enums\Role;
use App\Models\Activity;
use App\Models\LoginAttempt;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('records edits with old and new values', function () {
    $admin = userWithRole(Role::SuperAdmin);
    $this->actingAs($admin);

    $user = User::factory()->create(['name' => 'Old Name']);
    $user->update(['name' => 'New Name']);

    $entry = Activity::query()->where('subject_id', $user->id)->where('event', 'updated')->sole();

    expect($entry->properties['old']['name'])->toBe('Old Name')
        ->and($entry->properties['attributes']['name'])->toBe('New Name')
        ->and($entry->causer_id)->toBe($admin->id)
        ->and($entry->reference_code)->toBe($user->username);
});

it('does not log passwords', function () {
    $user = User::factory()->create();
    $user->update(['password' => 'Another-Password-1']);

    $logged = Activity::query()->where('subject_id', $user->id)->get()->pluck('properties')->toJson();

    expect($logged)->not->toContain('password');
});

it('stores the branch of the subject', function () {
    $branch = branch('DHK');

    $entry = Activity::query()->where('subject_type', $branch->getMorphClass())->where('subject_id', $branch->id)->first();

    expect($entry?->branch_id)->toBe($branch->id);
});

it('is append-only, even for direct SQL', function () {
    $entry = Activity::query()->create(['log_name' => 'test', 'description' => 'original']);

    expect(fn () => DB::table('activity_log')->where('id', $entry->id)->update(['description' => 'changed']))
        ->toThrow(QueryException::class, 'append-only');

    expect(fn () => DB::table('activity_log')->where('id', $entry->id)->delete())
        ->toThrow(QueryException::class, 'append-only');

    expect(Activity::query()->find($entry->id)->description)->toBe('original');
});

it('keeps login attempts append-only', function () {
    $attempt = LoginAttempt::query()->create(['username_tried' => 'x', 'successful' => false]);

    expect(fn () => DB::table('login_attempts')->where('id', $attempt->id)->delete())
        ->toThrow(QueryException::class, 'append-only');
});

it('does not let the Super Admin edit or delete log entries', function () {
    $admin = userWithRole(Role::SuperAdmin);
    $entry = Activity::query()->create(['log_name' => 'test', 'description' => 'x']);

    expect($admin->can('update', $entry))->toBeFalse()
        ->and($admin->can('delete', $entry))->toBeFalse()
        ->and($admin->can('view', $entry))->toBeTrue();
});
