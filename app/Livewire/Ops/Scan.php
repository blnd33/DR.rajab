<?php

namespace App\Livewire\Ops;

use App\Enums\ScanContext;
use App\Models\User;
use App\Services\Barcode\ScanResolver;
use App\Support\CurrentBranch;
use App\Support\Locale\DigitConverter;
use App\Support\ScanCard;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * "Scan anything" — camera or hardware scanner. Every scan is resolved through the
 * barcode parsers to a unit, product or lot (FEFO suggestion included); unknown codes
 * are saved so new label formats can be added.
 */
#[Layout('layouts::ops')]
class Scan extends Component
{
    /** @var list<array<string, mixed>> newest first */
    public array $results = [];

    public function handleScan(string $raw, string $source = 'manual'): void
    {
        $raw = DigitConverter::toWestern(trim($raw)) ?? '';

        if ($raw === '') {
            return;
        }

        /** @var User $user */
        $user = Auth::user();
        $branchId = CurrentBranch::id();

        $result = app(ScanResolver::class)->resolve(mb_substr($raw, 0, 500), ScanContext::Lookup, $user, $branchId);
        $card = ScanCard::from($result, $user, $branchId, in_array($source, ['camera', 'wedge', 'manual'], true) ? $source : 'manual');

        array_unshift($this->results, $card);
        $this->results = array_slice($this->results, 0, 10);

        $this->dispatch('scan-feedback', type: ScanCard::isGood($card) ? 'success' : 'error');
    }

    public function clear(): void
    {
        $this->results = [];
    }

    public function render(): View
    {
        /** @var User $user */
        $user = Auth::user();
        $branchId = CurrentBranch::id();

        return view('livewire.ops.scan', [
            'canReceive' => $branchId !== null && $user->canInBranch('stock.receive', $branchId),
        ])->title(__('Scan'));
    }
}
