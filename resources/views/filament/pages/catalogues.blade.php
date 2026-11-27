{{-- Catalogues → a category: a window for each company; a company opens its catalogue documents. --}}
<x-filament-panels::page>
    @php($companies = $this->companies())

    @if ($companies === [])
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ __('No company has catalogues in this category yet. Add a folder in Administration → Document folders, in the Catalogues section, and choose this category.') }}
        </p>
    @else
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-6" data-catalogue-companies>
            @foreach ($companies as $company)
                <a href="{{ $company['url'] }}" wire:navigate
                   class="flex min-h-20 flex-col items-center justify-center gap-2 rounded-xl bg-white p-3 text-center font-semibold text-gray-800 shadow-sm ring-1 ring-gray-950/5 transition hover:bg-primary-50 hover:text-primary-700 hover:ring-primary-600/30 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 dark:bg-gray-900 dark:text-gray-100 dark:ring-white/10 dark:hover:bg-primary-500/10">
                    @svg('heroicon-o-building-office-2', 'size-10 text-primary-600 dark:text-primary-400')
                    <span class="ot-section-window-label">{{ $company['name'] }}</span>
                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ trans_choice(':count document|:count documents', $company['documents'], ['count' => \App\Support\Locale\Display::number($company['documents'])]) }}</span>
                </a>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
