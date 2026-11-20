{{--
    The button that folds / unfolds the side menu, on its own line at the top of the menu (above
    Home page) instead of in the top bar next to the company name. Computers only: on a phone the
    menu opens from the top bar as before.
--}}
@php
    use Filament\Support\Icons\Heroicon;

    $isRtl = __('filament-panels::layout.direction') === 'rtl';
@endphp

<div class="ot-sidebar-toggle">
    <x-filament::icon-button
        color="gray"
        :icon="$isRtl ? Heroicon::OutlinedChevronRight : Heroicon::OutlinedChevronLeft"
        icon-size="lg"
        :label="__('filament-panels::layout.actions.sidebar.collapse.label')"
        x-cloak
        x-data="{}"
        aria-controls="fi-main-sidebar"
        x-bind:aria-expanded="$store.sidebar.isOpen"
        x-on:click="$store.sidebar.close()"
        x-show="$store.sidebar.isOpen"
    />
    <x-filament::icon-button
        color="gray"
        :icon="$isRtl ? Heroicon::OutlinedChevronLeft : Heroicon::OutlinedChevronRight"
        icon-size="lg"
        :label="__('filament-panels::layout.actions.sidebar.expand.label')"
        x-cloak
        x-data="{}"
        aria-controls="fi-main-sidebar"
        x-bind:aria-expanded="$store.sidebar.isOpen"
        x-on:click="$store.sidebar.open()"
        x-show="! $store.sidebar.isOpen"
    />
</div>
