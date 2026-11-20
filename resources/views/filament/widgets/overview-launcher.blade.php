{{-- The centre of the Home page: specialties + search, then stations, stock, value, sales, profit. --}}
<x-filament-widgets::widget>
    <div class="mx-auto flex w-full max-w-6xl flex-col gap-4" data-overview-launcher>
        {{-- Row 1: the specialties and the search. --}}
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-6">
            @foreach ($specialties as $item)
                <a href="{{ $item['url'] }}" wire:navigate
                   class="flex min-h-20 flex-col items-center justify-center gap-2 rounded-xl bg-white p-3 text-center font-semibold text-gray-800 shadow-sm ring-1 ring-gray-950/5 transition hover:bg-primary-50 hover:text-primary-700 hover:ring-primary-600/30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 dark:bg-gray-900 dark:text-gray-100 dark:ring-white/10 dark:hover:bg-primary-500/10">
                    @svg($item['icon'], 'size-10 text-primary-600 dark:text-primary-400')
                    <span>{{ $item['label'] }}</span>
                </a>
            @endforeach

            {{-- Search the whole system. --}}
            <div class="relative col-span-2 md:col-span-4 xl:col-span-2" x-data x-on:keydown.escape="$wire.set('search', '')">
                <label for="system-search" class="sr-only">{{ __('Search the whole system') }}</label>
                <div class="flex h-full min-h-20 items-center gap-3 rounded-xl bg-white px-4 shadow-sm ring-1 ring-gray-950/5 focus-within:ring-2 focus-within:ring-primary-600 dark:bg-gray-900 dark:ring-white/10">
                    @svg('heroicon-o-magnifying-glass', 'size-6 shrink-0 text-gray-400')
                    <input id="system-search" type="search" wire:model.live.debounce.400ms="search" autocomplete="off"
                           placeholder="{{ __('Search the whole system') }}"
                           class="w-full border-0 bg-transparent p-0 text-base shadow-none outline-none ring-0 focus:border-0 focus:shadow-none focus:outline-none focus:ring-0 [&::-webkit-search-cancel-button]:cursor-pointer dark:text-white">
                    <span wire:loading wire:target="search" class="text-xs text-gray-400">…</span>
                </div>

                @if ($results !== null)
                    <div class="absolute inset-x-0 top-full z-30 mt-2 max-h-[28rem] overflow-y-auto rounded-xl bg-white p-2 shadow-xl ring-1 ring-gray-950/10 dark:bg-gray-900 dark:ring-white/10">
                        @forelse ($results as $group)
                            <p class="px-2 pb-1 pt-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $group['group'] }}</p>
                            <ul>
                                @foreach ($group['items'] as $item)
                                    <li>
                                        @if ($item['url'])
                                            <a href="{{ $item['url'] }}" class="block rounded-lg px-2 py-2 hover:bg-gray-50 dark:hover:bg-white/5">
                                        @else
                                            <div class="block px-2 py-2">
                                        @endif
                                            <span class="block font-medium" dir="auto">{{ $item['title'] }}</span>
                                            @if ($item['details'])
                                                <span class="block text-xs text-gray-500 dark:text-gray-400" dir="auto">{{ $item['details'] }}</span>
                                            @endif
                                        @if ($item['url'])
                                            </a>
                                        @else
                                            </div>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @empty
                            <p class="px-2 py-4 text-sm text-gray-500 dark:text-gray-400">{{ __('Nothing found for ":text".', ['text' => $this->search]) }}</p>
                        @endforelse
                    </div>
                @endif
            </div>
        </div>

        {{-- Row 2: stations, stock, value, sales, profit. --}}
        @if ($metrics !== [])
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                @foreach ($metrics as $item)
                    <a href="{{ $item['url'] }}" wire:navigate
                       class="flex min-h-20 flex-col items-center justify-center gap-2 rounded-xl bg-primary-600 p-3 text-center font-semibold text-white shadow-sm transition hover:bg-primary-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600">
                        @svg($item['icon'], 'size-7')
                        <span>{{ $item['label'] }}</span>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</x-filament-widgets::widget>
