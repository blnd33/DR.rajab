{{-- A section's home page: a window for each entry of the section (as in the side menu). --}}
<x-filament-panels::page>
    <div class="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-6" data-section-windows>
        @foreach ($this->items() as $item)
            <a href="{{ $item['url'] }}" wire:navigate
               class="flex min-h-20 flex-col items-center justify-center gap-2 rounded-xl bg-white p-3 text-center font-semibold text-gray-800 shadow-sm ring-1 ring-gray-950/5 transition hover:bg-primary-50 hover:text-primary-700 hover:ring-primary-600/30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 dark:bg-gray-900 dark:text-gray-100 dark:ring-white/10 dark:hover:bg-primary-500/10">
                {{ \Filament\Support\generate_icon_html($item['icon'], size: \Filament\Support\Enums\IconSize::Large, attributes: new \Illuminate\View\ComponentAttributeBag(['class' => 'text-primary-600 dark:text-primary-400'])) }}
                <span class="ot-section-window-label">{{ $item['label'] }}</span>
                @if (filled($item['badge']))
                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $item['badge'] }}</span>
                @endif
            </a>
        @endforeach
    </div>
</x-filament-panels::page>
