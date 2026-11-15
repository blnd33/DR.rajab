@props(['variant' => 'inline'])

@php
    $locales = app(\App\Support\Locale\LocaleRegistry::class);
    $current = app()->getLocale();
@endphp

@if ($variant === 'compact')
    {{-- Top bar: a small globe + language list, available on every page after login. --}}
    <form method="POST" action="{{ route('locale.switch', $current) }}" x-data
          {{ $attributes->class(['relative flex items-center']) }}>
        @csrf
        <label for="language-switch-{{ $variant }}" class="sr-only">{{ __('Language') }}</label>
        <span class="pointer-events-none absolute start-2 flex items-center text-slate-500" aria-hidden="true">
            @svg('heroicon-o-language', 'size-5')
        </span>
        <select id="language-switch-{{ $variant }}"
                data-language-switch
                @change="$el.form.action = $el.selectedOptions[0].dataset.url; $el.form.submit()"
                class="h-10 cursor-pointer appearance-none rounded-lg border border-slate-300 bg-white py-0 ps-9 pe-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-700 dark:border-white/15 dark:bg-white/5 dark:text-slate-200">
            @foreach ($locales->all() as $code => $locale)
                <option value="{{ $code }}" lang="{{ $code }}" data-url="{{ route('locale.switch', $code) }}" @selected($code === $current)>{{ $locale['native'] }}</option>
            @endforeach
        </select>
    </form>
@else
    {{-- Each language is shown in its own script so anyone can find theirs. --}}
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
                        'min-h-11 rounded-full px-4 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cyan-700',
                        'bg-cyan-700 text-white' => $code === $current,
                        'bg-white text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50' => $code !== $current,
                    ])
                >{{ $locale['native'] }}</button>
            </form>
        @endforeach
    </nav>
@endif
