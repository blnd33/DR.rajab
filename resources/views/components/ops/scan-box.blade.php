{{-- Camera + scanner gun + typed code. Needs an ancestor with x-data="scanner(…)". --}}
@props(['id' => 'scan-code', 'hint' => null])
<section {{ $attributes->class(['ot-card space-y-3 p-4']) }} aria-label="{{ __('Scan') }}">
    @if ($hint)
        <p class="text-sm font-semibold">{{ $hint }}</p>
    @endif
    <div class="relative aspect-video w-full overflow-hidden rounded-lg bg-fg" wire:ignore x-show="cameraOn" x-cloak>
        <video x-ref="video" class="h-full w-full object-cover" playsinline muted aria-label="{{ __('Camera preview') }}"></video>
        <div class="pointer-events-none absolute inset-6 rounded-xl border-4 border-white/80"></div>
    </div>
    <button type="button" class="ot-btn w-full" :class="cameraOn ? 'ot-btn-secondary' : 'ot-btn-primary'" @click="toggleCamera()" :disabled="starting">
        @svg('heroicon-o-camera', 'size-5')
        <span x-show="!cameraOn">{{ __('Scan with camera') }}</span>
        <span x-show="cameraOn" x-cloak>{{ __('Stop camera') }}</span>
    </button>
    <form @submit.prevent="submitManual()" class="flex gap-2" wire:ignore>
        <label for="{{ $id }}" class="sr-only">{{ __('Code (scanner or keyboard)') }}</label>
        <input id="{{ $id }}" x-model="manual" data-scan-field data-western-digits class="ot-input ot-code" dir="ltr" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="{{ __('Scan or type a code') }}">
        <button type="submit" class="ot-btn ot-btn-secondary shrink-0">{{ __('Add') }}</button>
    </form>
    {{ $slot }}
</section>
