@php
    $locales = app(\App\Support\Locale\LocaleRegistry::class);
    $user = auth()->user();
    $flash = session('toast');
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $locales->direction() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ app(\App\Services\Settings\SettingsRepository::class)->get('company.name', config('app.name')) }}</title>
    <link rel="icon" href="{{ asset('icons/favicon.svg') }}" type="image/svg+xml">
    <x-pwa-head />
    @vite(['resources/css/ops.css', 'resources/js/ops/app.js'])
    @livewireStyles
</head>
<body class="min-h-dvh bg-bg text-fg antialiased">
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:start-2 focus:top-2 focus:z-50 focus:rounded-lg focus:bg-white focus:px-4 focus:py-2">
        {{ __('Skip to content') }}
    </a>

    {{-- Offline / waiting-to-sync banner --}}
    <div x-data="connectivity" x-cloak x-show="visible" role="status" aria-live="polite"
         class="ot-safe-top sticky top-0 z-40"
         :class="online ? 'bg-info text-white' : 'bg-fg text-white'">
        <div class="mx-auto flex max-w-screen-md items-center gap-2 px-4 py-2 text-sm font-medium">
            <template x-if="!online">
                <span class="flex items-center gap-2">
                    @svg('heroicon-o-signal-slash', 'size-5 shrink-0')
                    <span>{{ __('Offline — saved on this phone') }}</span>
                </span>
            </template>
            <template x-if="online && pending > 0">
                <span class="flex items-center gap-2">
                    @svg('heroicon-o-cloud-arrow-up', 'size-5 shrink-0')
                    <span x-text="@js(__('Waiting to sync:')) + ' ' + pending"></span>
                </span>
            </template>
        </div>
    </div>

    <x-ops.top-bar :user="$user" />

    <main id="main" class="mx-auto max-w-screen-md px-4 pb-28 pt-4">
        {{ $slot }}
    </main>

    <x-ops.install-banner />

    <x-ops.bottom-nav />

    {{-- Toasts --}}
    <div x-data="toasts" data-flash='@json($flash)'
         class="pointer-events-none fixed inset-x-0 bottom-24 z-50 flex flex-col items-center gap-2 px-4"
         role="region" aria-live="polite" aria-label="{{ __('Notifications') }}">
        <template x-for="item in items" :key="item.id">
            <div class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-xl px-4 py-3 text-sm font-medium text-white shadow-lg"
                 :class="{
                    'bg-success': item.type === 'success',
                    'bg-danger': item.type === 'error',
                    'bg-warning': item.type === 'warning',
                    'bg-fg': item.type === 'info',
                 }">
                <span class="mt-0.5 shrink-0" x-show="item.type === 'success'">@svg('heroicon-o-check-circle', 'size-5')</span>
                <span class="mt-0.5 shrink-0" x-show="item.type === 'error'">@svg('heroicon-o-x-circle', 'size-5')</span>
                <span class="mt-0.5 shrink-0" x-show="item.type === 'warning'">@svg('heroicon-o-exclamation-triangle', 'size-5')</span>
                <span class="mt-0.5 shrink-0" x-show="item.type === 'info'">@svg('heroicon-o-information-circle', 'size-5')</span>
                <span class="flex-1" x-text="item.message"></span>
                <button type="button" class="-m-1 p-1 opacity-80 hover:opacity-100" @click="dismiss(item.id)" aria-label="{{ __('Close') }}">
                    @svg('heroicon-o-x-mark', 'size-4')
                </button>
            </div>
        </template>
    </div>

    @livewireScriptConfig
</body>
</html>
