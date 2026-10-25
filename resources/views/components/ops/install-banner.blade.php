<div x-data="installPrompt" x-cloak class="fixed inset-x-0 bottom-20 z-30 px-4">
    {{-- Android / desktop --}}
    <div x-show="showAndroid" x-transition.opacity
         class="ot-card mx-auto flex max-w-screen-md items-center gap-3 p-3">
        @svg('heroicon-o-device-phone-mobile', 'size-8 shrink-0 text-primary')
        <p class="flex-1 text-sm">{{ __('Install the app on this phone for faster access and offline drafts.') }}</p>
        <button type="button" class="ot-btn ot-btn-primary" @click="install()">{{ __('Install') }}</button>
        <button type="button" class="ot-btn ot-btn-secondary px-3" @click="dismiss()" aria-label="{{ __('Not now') }}">
            @svg('heroicon-o-x-mark', 'size-5')
        </button>
    </div>

    {{-- iOS: no install prompt exists, show the steps --}}
    <div x-show="showIos" x-transition.opacity
         class="ot-card mx-auto flex max-w-screen-md items-start gap-3 p-3">
        @svg('heroicon-o-arrow-up-on-square', 'size-8 shrink-0 text-primary')
        <p class="flex-1 text-sm">
            {{ __('To install: tap the Share button, then "Add to Home Screen". This is also needed for notifications on iPhone.') }}
        </p>
        <button type="button" class="ot-btn ot-btn-secondary px-3" @click="dismiss()" aria-label="{{ __('Not now') }}">
            @svg('heroicon-o-x-mark', 'size-5')
        </button>
    </div>
</div>
