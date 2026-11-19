<?php

use App\Enums\IssueSeverity;
use App\Enums\IssueType;
use App\Enums\Role;
use App\Filament\Admin\Auth\EditProfile;
use App\Filament\Admin\Pages\MorningSummaryPage;
use App\Livewire\Ops\Profile;
use App\Models\MorningSummary;
use App\Notifications\MorningSummaryReady;
use App\Services\Insight\MorningSummaryBuilder;
use App\Services\Insight\MorningSummaryService;
use App\Services\Insight\SummarySettings;
use App\Services\Issues\IssueService;
use App\Services\Settings\SettingsRepository;
use Database\Seeders\InventoryBaseSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Morning summary (Phase 6): each person who may receive it gets yesterday's numbers and
 * what needs attention, once a day at their own time; every sent summary is kept.
 */

beforeEach(function () {
    $this->seed(InventoryBaseSeeder::class);
    $this->dhk = branch('DHK');
    $this->ebl = branch('EBL');
    $this->admin = userWithRole(Role::SuperAdmin, [$this->dhk]);
    $this->accountant = userWithRole(Role::Accountant, [$this->dhk]);
    $this->service = app(MorningSummaryService::class);

    // Wednesday 11 November 2026, 07:45 in Baghdad.
    $this->travelTo(Carbon::parse('2026-11-11 07:45', 'Asia/Baghdad'));
});

function baghdad(string $time): Carbon
{
    return Carbon::parse($time, 'Asia/Baghdad');
}

it('uses the defaults from Settings until a person chooses their own', function () {
    app(SettingsRepository::class)->setMany(['summary.time' => '06:15', 'summary.sections' => ['money', 'stock']]);

    $settings = SummarySettings::for($this->accountant);
    expect($settings->toArray())->toBe(['enabled' => true, 'time' => '06:15', 'sections' => ['money', 'stock'], 'branch_id' => null, 'skip_fridays' => false]);

    $own = SummarySettings::save($this->accountant, ['enabled' => true, 'time' => '08:00', 'sections' => ['kits', 'nonsense'], 'branch_id' => $this->dhk->id, 'skip_fridays' => true]);
    expect($own->toArray())->toBe(['enabled' => true, 'time' => '08:00', 'sections' => ['kits'], 'branch_id' => $this->dhk->id, 'skip_fridays' => true]);

    expect(fn () => SummarySettings::save($this->accountant, ['time' => '25:00']))->toThrow(ValidationException::class)
        ->and(fn () => SummarySettings::save($this->accountant, ['time' => '07:00', 'branch_id' => $this->ebl->id]))->toThrow(ValidationException::class);
});

it('is due once a day at the person\'s time, not on a skipped Friday, only with the permission', function () {
    expect($this->service->isDue($this->accountant, baghdad('2026-11-11 07:15')))->toBeFalse()
        ->and($this->service->isDue($this->accountant, baghdad('2026-11-11 07:30')))->toBeTrue()
        ->and($this->service->isDue(userWithRole(Role::BranchStaff, [$this->dhk]), baghdad('2026-11-11 09:00')))->toBeFalse();

    $this->service->send($this->accountant, '2026-11-11');
    expect($this->service->isDue($this->accountant, baghdad('2026-11-11 10:00')))->toBeFalse()
        ->and($this->service->isDue($this->accountant, baghdad('2026-11-12 07:30')))->toBeTrue();

    SummarySettings::save($this->accountant, ['enabled' => true, 'time' => '07:30', 'sections' => ['money'], 'skip_fridays' => true]);
    expect($this->service->isDue($this->accountant, baghdad('2026-11-13 09:00')))->toBeFalse(); // Friday

    SummarySettings::save($this->accountant, ['enabled' => false, 'time' => '07:30', 'sections' => ['money']]);
    expect($this->service->isDue($this->accountant, baghdad('2026-11-12 09:00')))->toBeFalse();
});

it('sends the due summaries once, keeps them, and notifies with counts only', function () {
    Notification::fake();
    app(IssueService::class)->open(IssueType::CashDifference, IssueSeverity::Warning, $this->dhk->id, null, 'Duhok difference');

    expect($this->service->sendDue())->toBe(2) // Super Admin and Accountant (07:30 default, now 07:45)
        ->and($this->service->sendDue())->toBe(0);

    $summary = MorningSummary::query()->where('user_id', $this->accountant->id)->sole();
    expect($summary->summary_date->toDateString())->toBe('2026-11-11')
        ->and($summary->payload['day'])->toBe('2026-11-10')
        ->and($summary->payload['sections']['attention'][0]['title'])->toBe('Open issues')
        // Empty facts are left out: nothing was sold yesterday.
        ->and(collect($summary->payload['sections'])->flatten(1)->pluck('key'))->not->toContain('sales');

    Notification::assertSentTo($this->accountant, MorningSummaryReady::class, function (MorningSummaryReady $n) {
        $data = $n->toArray($this->accountant);

        return $data['title'] === 'Daily Business Report 2026-11-11'
            && $data['body'] === 'Yesterday: 1 open issue'
            && str_contains($data['url'], '/admin/morning-summary?date=2026-11-11');
    });
});

it('leaves out the sections a person did not choose, and says when nothing happened', function () {
    SummarySettings::save($this->accountant, ['enabled' => true, 'time' => '07:30', 'sections' => ['money']]);
    app(IssueService::class)->open(IssueType::CashDifference, IssueSeverity::Warning, $this->dhk->id, null, 'Duhok difference');

    $payload = app(MorningSummaryBuilder::class)->build($this->accountant->fresh(), '2026-11-11');

    expect(array_keys($payload['sections']))->not->toContain('attention')
        ->and(MorningSummaryBuilder::headline(['sections' => []]))->toBe('Yesterday was quiet and nothing is waiting.');
});

it('builds the push line from numbers only, never from names in the lines', function () {
    $payload = ['currency' => 'USD', 'sections' => ['day' => [
        ['key' => 'surgeries-done', 'numbers' => ['surgeries' => 2], 'lines' => [['label' => 'Patient Ahmad Karim', 'value' => '1']]],
        ['key' => 'sales', 'numbers' => ['total' => '1250.00'], 'lines' => []],
    ], 'kits' => [['key' => 'kits-out', 'numbers' => ['out' => 3, 'overdue' => 1], 'lines' => []]]]];

    expect(MorningSummaryBuilder::headline($payload))->toBe('Yesterday: 2 surgeries · USD 1,250.00 in sales · 1 instrument set overdue')
        ->not->toContain('Ahmad');
});

it('writes the summary in the person\'s language', function () {
    $this->accountant->update(['locale' => 'ar']);
    app(IssueService::class)->open(IssueType::CashDifference, IssueSeverity::Warning, $this->dhk->id, null, 'Duhok difference');

    $payload = app(MorningSummaryBuilder::class)->build($this->accountant->fresh(), '2026-11-11');

    expect($payload['locale'])->toBe('ar')
        ->and($payload['sections']['attention'][0]['title'])->toBe(__('Open issues', [], 'ar'))
        ->and(app()->getLocale())->toBe('en');
});

it('shows any morning on the summary page, as sent or built now', function () {
    $this->service->send($this->accountant, '2026-11-10');

    Livewire::actingAs($this->accountant)->test(MorningSummaryPage::class, ['date' => '2026-11-10'])
        ->assertOk()
        ->assertSee('2026-11-09')
        ->assertSee('Sent');

    Livewire::actingAs($this->accountant)->test(MorningSummaryPage::class)
        ->assertSee('Not sent yet — built now with the latest numbers.')
        ->set('date', '2030-01-01')
        ->assertSet('date', '2026-11-11');

    $this->actingAs(userWithRole(Role::BranchStaff, [$this->dhk]));
    expect(MorningSummaryPage::canAccess())->toBeFalse();
});

it('has the summary on the phone, with its settings on the Me page', function () {
    $this->actingAs($this->admin)->get(route('ops.summary', ['date' => '2026-11-11']))->assertOk()->assertSee('Daily Business Report');
    $this->actingAs($this->admin)->get(route('ops.home'))->assertOk()->assertSee('Arrives at 07:30');

    Livewire::actingAs($this->admin)->test(Profile::class)
        ->set('summary.time', '06:45')
        ->set('summary.sections', ['kits'])
        ->call('saveSummary')
        ->assertHasNoErrors();

    expect(SummarySettings::for($this->admin->fresh())->toArray())->toMatchArray(['time' => '06:45', 'sections' => ['kits']]);

    Livewire::actingAs($this->admin)->test(Profile::class)->set('summary.time', 'soon')->call('saveSummary')->assertHasErrors('summary.time');

    $this->actingAs(userWithRole(Role::Doctor, [$this->dhk]))->get(route('ops.summary'))->assertForbidden();
});

it('saves the summary settings from the admin profile', function () {
    Livewire::actingAs($this->accountant)->test(EditProfile::class)
        ->fillForm(['summary.time' => '06:00', 'summary.skip_fridays' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(SummarySettings::for($this->accountant->fresh()))->time->toBe('06:00')->skipFridays->toBeTrue();
});

it('sends one person\'s summary from the command line', function () {
    Notification::fake();

    $this->artisan('summary:send', ['--user' => $this->accountant->username])->assertSuccessful();
    $this->artisan('summary:send', ['--user' => 'nobody'])->assertFailed();

    Notification::assertSentTo($this->accountant, MorningSummaryReady::class);
});
