<?php

use App\Enums\DigitSystem;
use App\Support\Locale\DigitConverter;

it('converts Arabic-Indic and Persian digits to Western', function () {
    expect(DigitConverter::toWestern('١٢٣٤٥٦٧٨٩٠'))->toBe('1234567890')
        ->and(DigitConverter::toWestern('۱۲۳۴۵۶۷۸۹۰'))->toBe('1234567890')
        ->and(DigitConverter::toWestern('٣٫٥'))->toBe('3.5')
        ->and(DigitConverter::toWestern('١٬٢٥٠'))->toBe('1250');
});

it('leaves plain ASCII and text untouched', function () {
    expect(DigitConverter::toWestern('ABC-123'))->toBe('ABC-123')
        ->and(DigitConverter::toWestern('ئەژنۆ ٢'))->toBe('ئەژنۆ 2')
        ->and(DigitConverter::toWestern(null))->toBeNull()
        ->and(DigitConverter::toWestern(''))->toBe('');
});

it('formats numbers for display in Arabic-Indic digits', function () {
    expect(DigitConverter::forDisplay('1,234.5', DigitSystem::ArabicIndic))->toBe('١٬٢٣٤٫٥')
        ->and(DigitConverter::forDisplay('2026-10-25', DigitSystem::ArabicIndic))->toBe('٢٠٢٦-١٠-٢٥')
        ->and(DigitConverter::forDisplay(42, DigitSystem::Latin))->toBe('42');
});

it('never touches passwords or tokens when normalising input', function () {
    $input = DigitConverter::normalizeArray([
        'quantity' => '١٢',
        'password' => '١٢٣abc',
        'password_confirmation' => '١٢٣abc',
        '_token' => '٩٩',
        'nested' => ['price' => '٤٥٫٥', 'secret_key' => '٧'],
    ]);

    expect($input['quantity'])->toBe('12')
        ->and($input['password'])->toBe('١٢٣abc')
        ->and($input['password_confirmation'])->toBe('١٢٣abc')
        ->and($input['_token'])->toBe('٩٩')
        ->and($input['nested']['price'])->toBe('45.5')
        ->and($input['nested']['secret_key'])->toBe('٧');
});

it('normalises only updates and call params of a Livewire payload', function () {
    $snapshot = json_encode(['data' => ['qty' => '٣'], 'checksum' => 'abc']);

    $result = DigitConverter::normalizeLivewirePayload([[
        'snapshot' => $snapshot,
        'updates' => ['data.qty' => '٣', 'data.password' => '٣'],
        'calls' => [['method' => 'save', 'params' => ['٥']]],
    ]]);

    expect($result[0]['snapshot'])->toBe($snapshot)
        ->and($result[0]['updates']['data.qty'])->toBe('3')
        ->and($result[0]['updates']['data.password'])->toBe('٣')
        ->and($result[0]['calls'][0]['params'][0])->toBe('5');
});
