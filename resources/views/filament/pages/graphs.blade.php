<x-filament-panels::page>
    <div class="space-y-4">
        <div class="flex flex-col gap-3">
            <label for="graph-search" class="sr-only">{{ __('Search graphs') }}</label>
            <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass" class="w-full">
                <x-filament::input
                    id="graph-search"
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    :placeholder="__('Search graphs, e.g. surgeries, sales, hospital, expiry…')"
                    autocomplete="off"
                />
            </x-filament::input.wrapper>

            <div class="flex flex-wrap gap-2" role="group" aria-label="{{ __('Sections') }}">
                <x-filament::button
                    size="sm"
                    :color="$current === null ? 'primary' : 'gray'"
                    :outlined="$current !== null"
                    wire:click="$set('section', '')"
                >
                    {{ __('All') }} · <x-num :value="$total" />
                </x-filament::button>
                @foreach ($sections as $s)
                    <x-filament::button
                        size="sm"
                        :icon="$s->icon()"
                        :color="$current === $s ? 'primary' : 'gray'"
                        :outlined="$current !== $s"
                        wire:click="chooseSection('{{ $s->value }}')"
                        aria-pressed="{{ $current === $s ? 'true' : 'false' }}"
                    >
                        {{ $s->label() }}
                    </x-filament::button>
                @endforeach
            </div>
        </div>

        <p class="text-sm text-gray-500 dark:text-gray-400" aria-live="polite">
            {{ trans_choice(':count graph|:count graphs', count($graphs), ['count' => \App\Support\Locale\Display::number(count($graphs))]) }}
        </p>

        @if ($graphs === [])
            <x-filament::section>
                <div class="flex flex-col items-center gap-2 py-6 text-center">
                    @svg('heroicon-o-chart-pie', 'size-10 text-gray-400')
                    <p class="font-semibold">{{ __('No graph matches your search') }}</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Try another word, or clear the search to see every graph.') }}</p>
                    <x-filament::button color="gray" size="sm" wire:click="$set('search', '')">{{ __('Clear the search') }}</x-filament::button>
                </div>
            </x-filament::section>
        @else
            <ul class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($graphs as $graph)
                    <li wire:key="g-{{ $graph->key }}">
                        <a
                            href="{{ \App\Filament\Admin\Pages\ViewGraph::getUrl(['graph' => $graph->key]) }}"
                            class="group flex h-full min-h-28 items-start gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 transition hover:ring-primary-500 focus-visible:outline-2 focus-visible:outline-primary-600 dark:bg-gray-900 dark:ring-white/10 dark:hover:ring-primary-400"
                        >
                            <span class="flex size-11 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-400">
                                @svg($graph->icon(), 'size-6')
                            </span>
                            <span class="min-w-0 space-y-1">
                                <span class="block font-semibold text-gray-950 group-hover:text-primary-600 dark:text-white dark:group-hover:text-primary-400">{{ $graph->title }}</span>
                                <span class="block text-sm text-gray-500 dark:text-gray-400">{{ $graph->description }}</span>
                                <span class="block text-xs text-gray-400 dark:text-gray-500">{{ $graph->section->label() }} · {{ $graph->kindLabel() }}</span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-filament-panels::page>
