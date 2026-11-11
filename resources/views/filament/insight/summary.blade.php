{{-- The morning summary's sections (admin): one card per fact. $payload from MorningSummaryBuilder. --}}
@php
    use App\Services\Insight\FactSection;

    $tones = [
        'good' => 'text-success-600 dark:text-success-400',
        'warning' => 'text-warning-600 dark:text-warning-400',
        'danger' => 'text-danger-600 dark:text-danger-400',
    ];
@endphp
@if (($payload['sections'] ?? []) === [])
    <x-filament::section>
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ __('Nothing happened that day and nothing is waiting.') }}</p>
    </x-filament::section>
@endif
@foreach (FactSection::cases() as $section)
    @continue(empty($payload['sections'][$section->value]))
    <section class="space-y-3" aria-labelledby="summary-{{ $section->value }}">
        <h2 id="summary-{{ $section->value }}" class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $section->label() }}</h2>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($payload['sections'][$section->value] as $fact)
                <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <div class="flex items-start justify-between gap-3">
                        <h3 class="text-sm font-medium text-gray-600 dark:text-gray-300">{{ $fact['title'] }}</h3>
                        @if (filled($fact['url'] ?? null))
                            <a href="{{ $fact['url'] }}" class="shrink-0 text-xs font-semibold text-primary-600 hover:underline dark:text-primary-400">{{ __('Open the full list') }}</a>
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
                                    <span class="min-w-0 truncate text-gray-700 dark:text-gray-200" title="{{ $line['label'] }}">{{ $line['label'] }}</span>
                                    <span class="shrink-0 font-medium tabular-nums">{{ $line['value'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endforeach
        </div>
    </section>
@endforeach
