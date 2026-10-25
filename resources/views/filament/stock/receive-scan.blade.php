{{-- Scan box for admin stock screens: USB/Bluetooth scanners type the code and press Enter.
     A div (not a form): this sits inside the page's Filament form. --}}
<x-filament::section icon="heroicon-o-qr-code" :heading="__('Scan')" :description="__('Click the field and scan a box label. GS1 codes fill product, lot, expiry and serial.')">
    <div class="flex flex-col gap-2 sm:flex-row" role="search">
        <label for="receive-scan" class="sr-only">{{ __('Code (scanner or keyboard)') }}</label>
        <x-filament::input.wrapper class="flex-1" prefix-icon="heroicon-m-qr-code">
            <x-filament::input
                id="receive-scan"
                type="text"
                wire:model="scan"
                wire:keydown.enter.prevent="handleScan"
                autocomplete="off"
                autofocus
                dir="ltr"
                class="ot-code"
                :placeholder="__('Scan or type a code, then press Enter')"
            />
        </x-filament::input.wrapper>
        <x-filament::button type="button" wire:click="handleScan" icon="heroicon-m-magnifying-glass" wire:loading.attr="disabled" wire:target="handleScan">
            {{ __('Add') }}
        </x-filament::button>
    </div>
</x-filament::section>
