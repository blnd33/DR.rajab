{{-- One answer card (phone): a FactResult as array — morning summary and Quick answers. --}}
@props(['fact'])
@php($tones = ['good' => 'text-success', 'warning' => 'text-warning', 'danger' => 'text-danger'])
<article {{ $attributes->class(['ot-card space-y-2 p-4']) }}>
    <h3 class="text-sm font-medium text-muted-fg">{{ $fact['title'] }}</h3>
    <p @class(['text-2xl font-bold', $tones[$fact['tone'] ?? ''] ?? ''])>{{ $fact['headline'] }}</p>
    @if (filled($fact['note'] ?? null))
        <p class="text-xs text-muted-fg">{{ $fact['note'] }}</p>
    @endif
    @if (! empty($fact['lines']))
        <ul class="divide-y divide-border text-sm">
            @foreach ($fact['lines'] as $line)
                <li class="flex items-baseline justify-between gap-3 py-1.5">
                    <span class="min-w-0 flex-1 break-words">{{ $line['label'] }}</span>
                    @if (! empty($line['code']))
                        <x-code :value="$line['value']" class="max-w-[60%] break-all text-end font-medium" />
                    @else
                        <span class="max-w-[60%] text-end font-medium tabular-nums">{{ $line['value'] }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
    @if (filled($fact['url'] ?? null))
        <a href="{{ $fact['url'] }}" class="inline-flex min-h-11 items-center gap-1 text-sm font-semibold text-primary">
            {{ in_array($fact['key'] ?? '', \App\Services\Insight\CodeLookup::KINDS, true) ? __('Open') : __('Open the full list') }} @svg('heroicon-m-chevron-right', 'size-4 rtl:rotate-180')
        </a>
    @endif
</article>
