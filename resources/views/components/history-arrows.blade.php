{{--
    Back and forward, like a browser's arrows, at the start of the top bar. Dimmed when there is
    nowhere to go (where the browser tells; otherwise always on).
--}}
@php
    use Filament\Support\Icons\Heroicon;

    $isRtl = __('filament-panels::layout.direction') === 'rtl';
@endphp

<div class="ot-history"
     x-data="{
         back: window.navigation?.canGoBack ?? true,
         forward: window.navigation?.canGoForward ?? false,
         check() { this.back = window.navigation?.canGoBack ?? true; this.forward = window.navigation?.canGoForward ?? true },
     }"
     x-on:livewire:navigated.window="check()"
     x-on:popstate.window="setTimeout(() => check(), 50)">
    <x-filament::icon-button
        color="gray"
        :icon="$isRtl ? Heroicon::OutlinedArrowRight : Heroicon::OutlinedArrowLeft"
        :label="__('Go back')"
        x-on:click="history.back()"
        x-bind:disabled="! back"
        data-history-back
    />
    <x-filament::icon-button
        color="gray"
        :icon="$isRtl ? Heroicon::OutlinedArrowLeft : Heroicon::OutlinedArrowRight"
        :label="__('Go forward')"
        x-on:click="history.forward()"
        x-bind:disabled="! forward"
        data-history-forward
    />
</div>
