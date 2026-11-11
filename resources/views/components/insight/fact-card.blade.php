{{-- One answer card (admin): a FactResult as array — morning summary and Quick answers. --}}
@props(['fact'])
@php
    $tones = [
        'good' => 'text-success-600 dark:text-success-400',
        'warning' => 'text-warning-600 dark:text-warning-400',
        'danger' => 'text-danger-600 dark:text-danger-400',
    ];
@endphp
<div {{ $attributes->class(['fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10']) }}>
    <div class="flex items-start justify-between gap-3">
        <h3 class="min-w-0 text-sm font-medium text-gray-600 dark:text-gray-300">{{ $fact['title'] }}</h3>
        @if (filled($fact['url'] ?? null))
            <a href="{{ $fact['url'] }}" class="shrink-0 text-xs font-semibold text-primary-600 hover:underline dark:text-primary-400">{{ in_array($fact['key'] ?? '', \App\Services\Insight\CodeLookup::KINDS, true) ? __('Open') : __('Open the full list') }}</a>
        @endif
    </div>
    <p @class(['mt-1 text-2xl font-bold', $tones[$fact['tone'] ?? ''] ?? 'text-gray-950 dark:text-white'])>{{ $fact['headline'] }}</p>
    @if (filled($fact['note'] ?? null))
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $fact['note'] }}</p>
    @endif
    @if (! empty($fact['lines']))
        <ul class="mt-3 divide-y divide-gray-950/5 text-sm dark:divide-white/10">
            @foreach ($fact['lines'] as $line)
                <li class="flex items-baseline justify-between gap-3 py-1.5">
                    <span class="min-w-0 flex-1 truncate text-gray-700 dark:text-gray-200" title="{{ $line['label'] }}">{{ $line['label'] }}</span>
                    @if (! empty($line['code']))
                        <x-code :value="$line['value']" class="max-w-[65%] break-all text-end font-medium" />
                    @else
                        <span class="max-w-[65%] text-end font-medium tabular-nums">{{ $line['value'] }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
