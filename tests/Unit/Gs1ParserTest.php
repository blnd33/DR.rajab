<?php

use App\Services\Barcode\Parsers\Gs1Parser;
use App\Services\Barcode\Parsers\InternalLabelParser;
use App\Support\Gs1\Gtin;

function gs1Vectors(): array
{
    return json_decode(file_get_contents(__DIR__.'/../fixtures/gs1-vectors.json'), true);
}

it('reads GS1 element strings', function (array $vector) {
    $parsed = (new Gs1Parser)->parse($vector['raw'].($vector['raw_suffix'] ?? ''));

    expect($parsed)->not->toBeNull();

    $expect = $vector['expect'];

    foreach (['gtin' => 'gtin', 'lot' => 'lot', 'serial' => 'serial', 'expiry_date' => 'expiryDate', 'production_date' => 'productionDate'] as $key => $property) {
        if (array_key_exists($key, $expect)) {
            expect($parsed->{$property})->toBe($expect[$key], "{$vector['name']}: {$key}");
        }
    }

    if (isset($expect['extras'])) {
        expect($parsed->extras)->toMatchArray($expect['extras']);
    }

    expect(count($parsed->warnings))->toBe($expect['warnings'] ?? 0, $vector['name'].': warnings');
})->with(fn () => array_map(fn ($v) => [$v], gs1Vectors()['vectors']));

it('leaves plain codes to the other parsers', function (string $raw) {
    expect((new Gs1Parser)->parse($raw))->toBeNull();
})->with(fn () => gs1Vectors()['not_gs1']);

it('checks GTIN check digits and matches 13- and 14-digit forms', function () {
    expect(Gtin::isValid('09501101530003'))->toBeTrue()
        ->and(Gtin::isValid('09501101530004'))->toBeFalse()
        ->and(Gtin::isValid('4006381333931'))->toBeTrue()
        ->and(Gtin::toGtin14('4006381333931'))->toBe('04006381333931')
        ->and(Gtin::variants('04006381333931'))->toContain('4006381333931');
});

it('reads and writes our own label payloads', function () {
    $parser = new InternalLabelParser;

    expect($parser->parse(InternalLabelParser::unit('SN-1'))->serial)->toBe('SN-1')
        ->and($parser->parse(InternalLabelParser::product('KN-TIB-5'))->sku)->toBe('KN-TIB-5');

    $lot = $parser->parse(InternalLabelParser::lot('SC-1', 'L9', '2027-03-31'));

    expect($lot->sku)->toBe('SC-1')
        ->and($lot->lot)->toBe('L9')
        ->and($lot->expiryDate)->toBe('2027-03-31')
        ->and($parser->parse('SN-1'))->toBeNull();
});
