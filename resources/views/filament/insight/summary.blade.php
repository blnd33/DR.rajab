{{-- The morning summary's sections (admin): one card per fact. $payload from MorningSummaryBuilder. --}}
@if (($payload['sections'] ?? []) === [])
    <x-filament::section>
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('Nothing happened that day and nothing is waiting.') }}</p>
    </x-filament::section>
@endif
@foreach (\App\Services\Insight\FactSection::cases() as $section)
    @continue(empty($payload['sections'][$section->value]))
    <section class="space-y-3" aria-labelledby="summary-{{ $section->value }}">
        <h2 id="summary-{{ $section->value }}" class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $section->label() }}</h2>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($payload['sections'][$section->value] as $fact)
                <x-insight.fact-card :fact="$fact" />
            @endforeach
        </div>
    </section>
@endforeach
