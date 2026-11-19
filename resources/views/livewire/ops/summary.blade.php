<div class="space-y-6">
    @php
        use App\Services\Insight\FactSection;
        use App\Support\Locale\Display;

        $payload = $summary['payload'];
    @endphp

    <header class="space-y-2">
        <h1 class="flex items-center gap-2 text-2xl font-bold">@svg('heroicon-o-sun', 'size-7 text-warning') {{ __('Daily Business Report') }}</h1>
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
                <x-ops.fact-card :fact="$fact" />
            @endforeach
        </section>
    @endforeach
</div>
