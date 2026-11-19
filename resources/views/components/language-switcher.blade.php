@props(['variant' => 'inline'])

@php
    $locales = app(\App\Support\Locale\LocaleRegistry::class);
    $current = app()->getLocale();
    $currentNative = $locales->all()[$current]['native'] ?? $current;
@endphp

@if ($variant === 'compact')
    {{-- Top bar: the current language's flag; the menu lists every language with its flag. Available on every page after login. --}}
    <div x-data="{ open: false }" x-on:keydown.escape.window="open = false" x-on:click.outside="open = false"
         data-language-switch
         {{ $attributes->class(['relative flex items-center']) }}>
        <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" aria-haspopup="true"
                aria-label="{{ __('Language') }}: {{ $currentNative }}"
                class="flex h-10 cursor-pointer items-center gap-2 rounded-lg border border-slate-300 bg-white px-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-700 dark:border-white/15 dark:bg-white/5 dark:text-slate-200">
            <x-flag :locale="$current" />
            <span class="hidden sm:inline" lang="{{ $current }}">{{ $currentNative }}</span>
            <span class="text-slate-500" aria-hidden="true">@svg('heroicon-m-chevron-down', 'size-4')</span>
        </button>

        <div x-show="open" x-cloak x-transition.opacity.duration.100ms
             class="absolute end-0 top-full z-50 mt-2 w-44 overflow-hidden rounded-lg border border-slate-200 bg-white py-1 shadow-lg dark:border-white/10 dark:bg-slate-900">
            @foreach ($locales->all() as $code => $locale)
                <form method="POST" action="{{ route('locale.switch', $code) }}">
                    @csrf
                    {{-- Rows follow the page direction so the flags line up; each name keeps its own script. --}}
                    <button type="submit" lang="{{ $code }}"
                            @if ($code === $current) aria-current="true" @endif
                            @class([
                                'flex min-h-11 w-full items-center gap-3 px-3 text-start text-sm hover:bg-slate-50 focus-visible:bg-slate-100 focus-visible:outline-none dark:hover:bg-white/5',
                                'font-semibold text-cyan-800 dark:text-cyan-300' => $code === $current,
                                'text-slate-700 dark:text-slate-200' => $code !== $current,
                            ])>
                        <x-flag :locale="$code" />
                        <span class="flex-1">{{ $locale['native'] }}</span>
                        @if ($code === $current)
                            <span aria-hidden="true">@svg('heroicon-m-check', 'size-4')</span>
                        @endif
                    </button>
                </form>
            @endforeach
        </div>
    </div>
@else
    {{-- Each language is shown with its flag, in its own script, so anyone can find theirs. --}}
    <nav {{ $attributes->class(['flex flex-wrap items-center justify-center gap-2']) }} aria-label="{{ __('Language') }}">
        @foreach ($locales->all() as $code => $locale)
            <form method="POST" action="{{ route('locale.switch', $code) }}">
                @csrf
                <button
                    type="submit"
                    lang="{{ $code }}"
                    dir="{{ $locale['dir'] }}"
                    @if ($code === $current) aria-current="true" @endif
                    @class([
                        'inline-flex min-h-11 items-center gap-2 rounded-full px-4 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-700',
                        'bg-cyan-700 text-white' => $code === $current,
                        'bg-white text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50' => $code !== $current,
                    ])
                ><x-flag :locale="$code" /><span>{{ $locale['native'] }}</span></button>
            </form>
        @endforeach
    </nav>
@endif
