<div x-data="scanner({ method: 'handleScan' })" class="space-y-5">
    <header>
        <h1 class="text-2xl font-bold">{{ __('Scan anything') }}</h1>
        <p class="mt-1 text-muted-fg">{{ __('Point the camera at a box, or use a barcode scanner.') }}</p>
    </header>

    {{-- Camera --}}
    <section class="ot-card overflow-hidden" aria-label="{{ __('Camera') }}">
        <div class="relative aspect-[4/3] w-full bg-fg" wire:ignore>
            <video x-ref="video" class="h-full w-full object-cover" playsinline muted
                   x-show="cameraOn" aria-label="{{ __('Camera preview') }}"></video>

            {{-- Aiming frame --}}
            <div x-show="cameraOn" class="pointer-events-none absolute inset-10 rounded-2xl border-4 border-white/80"></div>

            <div x-show="!cameraOn" class="absolute inset-0 flex flex-col items-center justify-center gap-3 p-6 text-center text-white">
                @svg('heroicon-o-camera', 'size-12 opacity-80')
                <p x-show="!cameraError" class="text-sm opacity-90">{{ __('Camera is off') }}</p>
                <p x-show="cameraError === 'denied'" x-cloak class="text-sm">{{ __('Camera permission was denied. Allow it in the browser settings, or type the code below.') }}</p>
                <p x-show="cameraError === 'unavailable'" x-cloak class="text-sm">{{ __('No camera found on this device. Use a scanner or type the code below.') }}</p>
            </div>
        </div>

        <div class="p-3">
            <button type="button" class="ot-btn w-full" :class="cameraOn ? 'ot-btn-secondary' : 'ot-btn-primary'"
                    @click="toggleCamera()" :disabled="starting">
                @svg('heroicon-o-camera', 'size-5')
                <span x-show="!cameraOn && !starting">{{ __('Start camera') }}</span>
                <span x-show="starting" x-cloak>{{ __('Starting…') }}</span>
                <span x-show="cameraOn" x-cloak>{{ __('Stop camera') }}</span>
            </button>
        </div>
    </section>

    {{-- Manual / hardware scanner input --}}
    <form @submit.prevent="submitManual()" class="ot-card space-y-2 p-4" wire:ignore>
        <label for="scan-input" class="block text-sm font-semibold">{{ __('Code (scanner or keyboard)') }}</label>
        <div class="flex gap-2">
            <input id="scan-input" x-model="manual" data-scan-field data-western-digits
                   class="ot-input ot-code" dir="ltr" autocomplete="off" autocapitalize="off" spellcheck="false"
                   enterkeyhint="search" placeholder="0108712345678906 10ABC123">
            <button type="submit" class="ot-btn ot-btn-primary shrink-0">{{ __('Look up') }}</button>
        </div>
        <p class="text-xs text-muted-fg">{{ __('A USB or Bluetooth scanner works anywhere on this page.') }}</p>
    </form>

    {{-- Results --}}
    <section aria-labelledby="scan-results" class="space-y-3" aria-live="polite">
        <div class="flex items-center justify-between">
            <h2 id="scan-results" class="text-sm font-semibold uppercase tracking-wide text-muted-fg">{{ __('Scanned') }}</h2>
            @if ($history)
                <button type="button" wire:click="clear" class="text-sm font-medium text-primary">{{ __('Clear') }}</button>
            @endif
        </div>

        @forelse ($history as $index => $scan)
            <article wire:key="scan-{{ $index }}-{{ $scan['at'] }}" class="ot-card space-y-2 p-4">
                <x-code :value="$scan['raw']" class="block break-all text-base font-semibold" />
                <div class="flex flex-wrap items-center gap-2">
                    @if ($scan['gs1'])
                        <x-ops.status-chip :label="__('GS1 code')" color="info" icon="heroicon-o-qr-code" />
                    @else
                        <x-ops.status-chip :label="__('Plain barcode')" icon="heroicon-o-qr-code" />
                    @endif
                    <x-ops.status-chip :label="match ($scan['source']) { 'camera' => __('Camera'), 'wedge' => __('Scanner'), default => __('Typed') }" />
                    <x-date class="text-xs text-muted-fg" :value="$scan['at']" time />
                </div>
                <p class="text-sm text-muted-fg">{{ __('Product, unit and kit lookup arrives with the inventory module.') }}</p>
            </article>
        @empty
            <div class="ot-card">
                <x-ops.empty-state icon="heroicon-o-qr-code" :title="__('Nothing scanned yet')" :description="__('Scan a box label, a kit or an instrument.')" />
            </div>
        @endforelse
    </section>
</div>
