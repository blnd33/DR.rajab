<x-filament-panels::page>
    @php($payload = $summary['payload'])

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="space-y-1">
            <p class="text-lg font-semibold text-gray-950 dark:text-white">{{ $headline }}</p>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('Yesterday = :day.', ['day' => \App\Support\Locale\Display::date($payload['day'])]) }}
                @if ($summary['live'])
                    {{ __('Not sent yet — built now with the latest numbers.') }}
                @else
                    {{ __('Sent :time.', ['time' => \App\Support\Locale\Display::dateTime($summary['sent_at'])]) }}
                    @if (($payload['locale'] ?? app()->getLocale()) !== app()->getLocale())
                        {{ __('It was written in :language, the language you had when it was sent.', ['language' => app(\App\Support\Locale\LocaleRegistry::class)->nativeName($payload['locale'])]) }}
                    @endif
                @endif
                @unless ($settings->enabled)
                    {{ __('Your morning report is switched off.') }}
                @endunless
            </p>
        </div>
        <label class="flex items-center gap-2 text-sm">
            <span class="text-gray-600 dark:text-gray-300">{{ __('Morning of') }}</span>
            <input type="date" wire:model.live="date" max="{{ $today }}" dir="ltr"
                   class="rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-white/10 dark:bg-gray-900" />
            {{-- The browser shows the field in its own format; the date as the app writes it: --}}
            <span class="font-mono text-gray-600 dark:text-gray-300" dir="ltr">{{ $payload['date'] }}</span>
        </label>
    </div>

    @include('filament.insight.summary', ['payload' => $payload])
</x-filament-panels::page>
