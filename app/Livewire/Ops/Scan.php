<?php

namespace App\Livewire\Ops;

use App\Support\Locale\DigitConverter;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * "Scan anything" — camera or hardware scanner. Phase 0 shows what was read and
 * whether it looks like a GS1 code; Phase 1 resolves it to a product, unit, kit or
 * instrument through the barcode parsers (App\Services\Barcode).
 */
#[Layout('layouts::ops')]
class Scan extends Component
{
    /** @var list<array{raw: string, source: string, gs1: bool, at: string}> */
    public array $history = [];

    public function handleScan(string $raw, string $source = 'manual'): void
    {
        $raw = DigitConverter::toWestern(trim($raw)) ?? '';

        if ($raw === '') {
            return;
        }

        array_unshift($this->history, [
            'raw' => mb_substr($raw, 0, 500),
            'source' => in_array($source, ['camera', 'wedge', 'manual'], true) ? $source : 'manual',
            'gs1' => $this->looksLikeGs1($raw),
            'at' => now()->toIso8601String(),
        ]);

        $this->history = array_slice($this->history, 0, 10);
    }

    public function clear(): void
    {
        $this->history = [];
    }

    public function render(): View
    {
        return view('livewire.ops.scan')->title(__('Scan'));
    }

    /**
     * GS1 element strings start with an application identifier such as (01) GTIN,
     * and use the GS character (0x1D) between variable-length fields.
     */
    private function looksLikeGs1(string $raw): bool
    {
        return str_contains($raw, "\x1D")
            || str_starts_with($raw, '(01)')
            || (bool) preg_match('/^01\d{14}/', $raw);
    }
}
