<x-filament-widgets::widget>
    <a href="{{ $url }}" class="flex items-center gap-4 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 hover:bg-gray-50 dark:bg-gray-900 dark:ring-white/10 dark:hover:bg-white/5">
        <x-filament::icon icon="heroicon-o-sun" class="size-8 shrink-0 text-warning-500" />
        <div class="min-w-0 flex-1">
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('Morning summary') }}</p>
            @if ($headline !== null)
                <p class="font-semibold text-gray-950 dark:text-white">{{ $headline }}</p>
            @elseif ($settings->enabled)
                <p class="font-semibold text-gray-950 dark:text-white">{{ __('Arrives at :time. Open it now to see the latest numbers.', ['time' => \App\Support\Locale\Display::digits($settings->time)]) }}</p>
            @else
                <p class="font-semibold text-gray-950 dark:text-white">{{ __('Switched off — open it any time.') }}</p>
            @endif
        </div>
        <x-filament::icon icon="heroicon-m-chevron-right" class="size-5 shrink-0 text-gray-400 rtl:rotate-180" />
    </a>
</x-filament-widgets::widget>
