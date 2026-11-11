<div class="space-y-6">
    @php
        use App\Services\Insight\FactSection;
        use App\Support\Locale\Display;

        $payload = $summary['payload'];
        $tones = ['good' => 'text-success', 'warning' => 'text-warning', 'danger' => 'text-danger'];
    @endphp

    <header class="space-y-2">
        <h1 class="flex items-center gap-2 text-2xl font-bold">@svg('heroicon-o-sun', 'size-7 text-warning') {{ __('Morning summary') }}</h1>
        <p class="font-semibold">{{ $headline }}</p>
        <p class="text-sm text-muted-fg">
            {{ __('Yesterday = :day.', ['day' => Display::date($payload['day'])]) }}
            @if ($summary['live'])
                {{ __('Not sent yet — built now with the latest numbers.') }}
            @else
                {{ __('Sent :time.', ['time' => Display::dateTime($summary['sent_at'])]) }}
                @if (($payload['locale'] ?? app()->getLocale()) !== app()->getLocale())
                    {{ __('It was written in :language, the language you had when it was sent.', ['language' => app(\App\Support\Locale\LocaleRegistry::class)->nativeName($payload['locale'])]) }}
                @endif
            @endif
        </p>
        <label class="block space-y-1 text-sm">
            <span class="text-muted-fg">{{ __('Morning of') }}</span>
            <x-ops.date-input wire:model.live="date" max="{{ $today }}" />
        </label>
    </header>

    @if (($payload['sections'] ?? []) === [])
        <p class="ot-card p-4 text-muted-fg">{{ __('Nothing happened that day and nothing is waiting.') }}</p>
    @endif

    @foreach (FactSection::cases() as $section)
        @continue(empty($payload['sections'][$section->value]))
        <section class="space-y-3" aria-labelledby="summary-{{ $section->value }}">
            <h2 id="summary-{{ $section->value }}" class="text-sm font-semibold uppercase tracking-wide text-muted-fg">{{ $section->label() }}</h2>
            @foreach ($payload['sections'][$section->value] as $fact)
                <article class="ot-card space-y-2 p-4">
                    <h3 class="text-sm font-medium text-muted-fg">{{ $fact['title'] }}</h3>
                    <p @class(['text-2xl font-bold', $tones[$fact['tone'] ?? ''] ?? ''])>{{ $fact['headline'] }}</p>
                    @if (filled($fact['note'] ?? null))
                        <p class="text-xs text-muted-fg">{{ $fact['note'] }}</p>
                    @endif
                    @if (! empty($fact['lines']))
                        <ul class="divide-y divide-border text-sm">
                            @foreach ($fact['lines'] as $line)
                                <li class="flex items-baseline justify-between gap-3 py-1.5">
                                    <span class="min-w-0 break-words">{{ $line['label'] }}</span>
                                    <span class="shrink-0 font-medium tabular-nums">{{ $line['value'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </article>
            @endforeach
        </section>
    @endforeach
</div>
