<?php

namespace App\Services\Labels;

use App\Enums\LabelSymbology;
use App\Models\Instrument;
use App\Models\Kit;
use App\Models\LabelTemplate;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\StockUnit;
use App\Services\Barcode\Parsers\InternalLabelParser;
use App\Support\Gs1\Gtin;
use Com\Tecnick\Barcode\Barcode;
use Illuminate\Support\Carbon;

/**
 * Our own labels (Module 5) for products, serial units and lots without a usable
 * barcode. Output is inline SVG placed on a page exactly the label size, printed by
 * the browser to the thermal printer's driver (P1-2). Kits and instruments carry their own code.
 *
 * What the barcode contains:
 *   Code 128          unit → serial · product → SKU · lot → OT1|L|sku|lot|expiry
 *   DataMatrix / QR   OT1|U|serial · OT1|P|sku · OT1|L|…   (InternalLabelParser reads them)
 *   GS1 DataMatrix    (01) GTIN (17) expiry (10) lot (21) serial — needs a product GTIN,
 *                     otherwise falls back to the DataMatrix payload above
 */
class LabelRenderer
{
    private const GS = "\x1D";

    public function __construct(private readonly Barcode $barcode = new Barcode) {}

    public function forUnit(LabelTemplate $template, StockUnit $unit): LabelItem
    {
        $product = $unit->product;

        [$payload, $symbology] = match (true) {
            $template->symbology === LabelSymbology::Gs1DataMatrix && $this->gtin14($product) !== null => [
                $this->gs1Payload($this->gtin14($product), $unit->expiry_date, $unit->lot, $unit->serial),
                LabelSymbology::Gs1DataMatrix,
            ],
            $template->symbology === LabelSymbology::Code128 => [$unit->serial, LabelSymbology::Code128],
            $template->symbology === LabelSymbology::Qr => [InternalLabelParser::unit($unit->serial), LabelSymbology::Qr],
            default => [InternalLabelParser::unit($unit->serial), LabelSymbology::DataMatrix],
        };

        return $this->item($template, $payload, $symbology, [
            'name' => $product->name,
            'sku' => $product->sku,
            'size' => (string) $product->size,
            'serial' => 'SN '.$unit->serial,
            'lot' => filled($unit->lot) ? 'LOT '.$unit->lot : '',
            'expiry' => $unit->expiry_date ? 'EXP '.$unit->expiry_date->format('Y-m-d') : '',
            'branch' => (string) $unit->branch?->code,
        ]);
    }

    public function forBatch(LabelTemplate $template, StockBatch $batch): LabelItem
    {
        $product = $batch->product;
        $expiry = $batch->expiry_date?->format('Y-m-d');

        [$payload, $symbology] = match (true) {
            $template->symbology === LabelSymbology::Gs1DataMatrix && $this->gtin14($product) !== null => [
                $this->gs1Payload($this->gtin14($product), $batch->expiry_date, $batch->lot, null),
                LabelSymbology::Gs1DataMatrix,
            ],
            $template->symbology === LabelSymbology::Code128 => [InternalLabelParser::lot($product->sku, $batch->lot, $expiry), LabelSymbology::Code128],
            $template->symbology === LabelSymbology::Qr => [InternalLabelParser::lot($product->sku, $batch->lot, $expiry), LabelSymbology::Qr],
            default => [InternalLabelParser::lot($product->sku, $batch->lot, $expiry), LabelSymbology::DataMatrix],
        };

        return $this->item($template, $payload, $symbology, [
            'name' => $product->name,
            'sku' => $product->sku,
            'size' => (string) $product->size,
            'serial' => '',
            'lot' => filled($batch->lot) ? 'LOT '.$batch->lot : '',
            'expiry' => $expiry !== null ? 'EXP '.$expiry : '',
            'branch' => (string) $batch->branch?->code,
        ]);
    }

    public function forProduct(LabelTemplate $template, Product $product): LabelItem
    {
        $gtin = $this->gtin14($product);

        [$payload, $symbology] = match (true) {
            $template->symbology === LabelSymbology::Gs1DataMatrix && $gtin !== null => ['01'.$gtin, LabelSymbology::Gs1DataMatrix],
            $template->symbology === LabelSymbology::Code128 => [$product->sku, LabelSymbology::Code128],
            $template->symbology === LabelSymbology::Qr => [InternalLabelParser::product($product->sku), LabelSymbology::Qr],
            default => [InternalLabelParser::product($product->sku), LabelSymbology::DataMatrix],
        };

        return $this->item($template, $payload, $symbology, [
            'name' => $product->name,
            'sku' => $product->sku,
            'size' => (string) $product->size,
            'serial' => '',
            'lot' => '',
            'expiry' => '',
            'branch' => '',
        ]);
    }

    /**
     * An instrument: the barcode holds its own label code (what scanning into a kit and the
     * return check look up). Text: type name, the instrument's code, the catalogue code, branch.
     */
    public function forInstrument(LabelTemplate $template, Instrument $instrument): LabelItem
    {
        return $this->asset($template, $instrument->barcode, [
            'name' => (string) $instrument->type?->name,
            'sku' => $instrument->code,
            'size' => '',
            'serial' => filled($instrument->type?->code) ? 'REF '.$instrument->type->code : '',
            'lot' => '',
            'expiry' => '',
            'branch' => (string) $instrument->branch?->code,
        ]);
    }

    /**
     * A kit box: the barcode holds the kit's label code. Text: kit type, the kit's code, home branch.
     */
    public function forKit(LabelTemplate $template, Kit $kit): LabelItem
    {
        return $this->asset($template, $kit->barcode, [
            'name' => (string) $kit->type?->name,
            'sku' => $kit->code,
            'size' => '',
            'serial' => filled($kit->type?->code) ? 'REF '.$kit->type->code : '',
            'lot' => '',
            'expiry' => '',
            'branch' => (string) $kit->homeBranch?->code,
        ]);
    }

    /**
     * Kits and instruments carry their plain code in every symbology (no GS1 data to encode, so a
     * GS1 DataMatrix template prints a plain DataMatrix). The code line is always printed.
     *
     * @param  array<string, string>  $values
     */
    private function asset(LabelTemplate $template, string $code, array $values): LabelItem
    {
        $symbology = match ($template->symbology) {
            LabelSymbology::Code128 => LabelSymbology::Code128,
            LabelSymbology::Qr => LabelSymbology::Qr,
            default => LabelSymbology::DataMatrix,
        };

        $item = $this->item($template, $code, $symbology, $values);

        if (isset($item->lines['sku'])) {
            return $item;
        }

        // The template leaves the code line out: put it right after the name anyway.
        $lines = array_intersect_key($item->lines, ['name' => true]) + ['sku' => $values['sku']] + $item->lines;

        return new LabelItem($item->payload, $item->svg, $item->twoDimensional, $lines);
    }

    /**
     * Inline SVG for any payload (used by previews).
     */
    public function svg(string $payload, LabelSymbology $symbology): string
    {
        $object = $this->barcode->getBarcodeObj(
            $symbology->libraryType(),
            $payload,
            $symbology->isTwoDimensional() ? -4 : -2,
            $symbology->isTwoDimensional() ? -4 : -40,
            'black',
            [0, 0, 0, 0],
        );

        $svg = (string) preg_replace('/^<\?xml[^>]*>\s*/', '', $object->getSvgCode());
        $svg = (string) preg_replace('/\s(width|height)="[^"]*"/', '', $svg, 2);

        $aspect = $symbology->isTwoDimensional() ? 'xMidYMid meet' : 'none';

        return str_replace('<svg ', '<svg class="ot-label-barcode" preserveAspectRatio="'.$aspect.'" role="img" aria-label="'.e($payload).'" ', $svg);
    }

    /**
     * @param  array<string, string>  $values
     */
    private function item(LabelTemplate $template, string $payload, LabelSymbology $symbology, array $values): LabelItem
    {
        $lines = [];

        foreach ($template->fields() as $field) {
            if (filled($values[$field] ?? null)) {
                $lines[$field] = $values[$field];
            }
        }

        return new LabelItem($payload, $this->svg($payload, $symbology), $symbology->isTwoDimensional(), $lines);
    }

    /**
     * (01) GTIN (17) expiry (10) lot GS (21) serial. Fixed-length AIs first, variable ones last,
     * GS only between variable fields — the shortest valid GS1 element string.
     */
    private function gs1Payload(string $gtin14, ?Carbon $expiry, ?string $lot, ?string $serial): string
    {
        $data = '01'.$gtin14;

        if ($expiry !== null) {
            $data .= '17'.$expiry->format('ymd');
        }

        $variable = [];

        if (filled($lot)) {
            $variable[] = '10'.$lot;
        }

        if (filled($serial)) {
            $variable[] = '21'.$serial;
        }

        return $data.implode(self::GS, $variable);
    }

    private function gtin14(Product $product): ?string
    {
        return filled($product->gtin) ? Gtin::toGtin14($product->gtin) : null;
    }
}
