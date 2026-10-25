{{-- Expiry level chip: the Super Admin's color + level name + days left (+ date unless compact). --}}
@props(['date' => null, 'compact' => false])

@php($e = \App\Support\ExpiryDisplay::for($date))

<span {{ $attributes->class(['inline-flex max-w-full flex-wrap items-center gap-x-2 gap-y-1']) }}>
    <span class="inline-flex shrink-0 items-center gap-1 whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset ring-black/10 dark:ring-white/30"
          style="background-color: {{ $e['color'] }}; color: {{ $e['text_color'] }};">
        @if ($e['level']?->is_expired_level)
            @svg('heroicon-m-no-symbol', 'size-3.5 shrink-0')
        @elseif ($e['level'])
            @svg('heroicon-m-clock', 'size-3.5 shrink-0')
        @endif
        <span>{{ $e['label'] }}</span>
    </span>
    @if ($e['days_text'] !== '')
        <span class="whitespace-nowrap text-xs font-medium text-gray-700 dark:text-gray-300">{{ $e['days_text'] }}</span>
    @endif
    @if (! $compact && $e['date'] !== '')
        <time class="whitespace-nowrap text-xs text-gray-500 dark:text-gray-400" dir="ltr" datetime="{{ $date instanceof \Carbon\CarbonInterface ? $date->format('Y-m-d') : $date }}">{{ $e['date'] }}</time>
    @endif
</span>
