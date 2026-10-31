<?php

use App\Enums\Role;
use App\Services\Settings\SettingsRepository;
use App\Support\Locale\Display;
use Illuminate\Support\Facades\Route;

it('renders the ops app right-to-left for Arabic and Sorani users', function (string $locale, string $dir) {
    $doctor = userWithRole(Role::Doctor, [branch('DHK')], ['locale' => $locale]);

    $this->actingAs($doctor)->get('/app')
        ->assertOk()
        ->assertSee('<html lang="'.$locale.'" dir="'.$dir.'">', false);
})->with([
    ['en', 'ltr'],
    ['ar', 'rtl'],
    ['ckb', 'rtl'],
]);

it('shows Sorani UI strings to a Sorani user', function () {
    $doctor = userWithRole(Role::Doctor, [branch('DHK')], ['locale' => 'ckb']);

    $this->actingAs($doctor)->get('/app')->assertSee('نەشتەرگەری نوێ');
});

it('saves a language switch on the account', function () {
    $doctor = userWithRole(Role::Doctor, [branch('DHK')]);

    $this->actingAs($doctor)->from('/app')->post(route('locale.switch', 'ar'))->assertRedirect('/app');

    expect($doctor->fresh()->locale)->toBe('ar');
});

it('rejects unsupported languages', function () {
    $this->post(route('locale.switch', 'fr'))->assertNotFound();
});

it('displays Arabic-Indic digits for Arabic and keeps dates as YYYY-MM-DD', function () {
    app()->setLocale('ar');

    expect(Display::number(1234.5, 1))->toBe('١٬٢٣٤٫٥')
        // Left-to-right marks keep year-month-day order inside RTL text.
        ->and(Display::date('2026-03-07'))->toBe("٢٠٢٦\u{200E}-\u{200E}٠٣\u{200E}-\u{200E}٠٧")
        ->and(Display::code('SN-00123'))->toBe('SN-00123');

    app()->setLocale('en');
    expect(Display::date('2026-03-07'))->toBe('2026-03-07');
});

it('lets Settings switch a language to Western digits', function () {
    app(SettingsRepository::class)->set('locale.digits.ckb', 'latn');
    app()->setLocale('ckb');

    expect(Display::number(42))->toBe('42');
});

it('normalises Arabic-Indic digits in form posts before validation', function () {
    Route::middleware('web')->post('/_test/digits', fn () => response()->json(request()->validate(['qty' => 'required|integer'])));

    $this->post('/_test/digits', ['qty' => '١٢'])->assertOk()->assertJson(['qty' => '12']);
});
