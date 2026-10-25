@props(['variant' => 'inline'])

@php
    $locales = app(\App\Support\Locale\LocaleRegistry::class);
    $current = app()->getLocale();
@endphp

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
