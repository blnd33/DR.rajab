<div class="space-y-5">
    <header class="flex items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">{{ __('Stock') }}</h1>
            @if ($branch)
                <p class="mt-1 text-muted-fg">{{ $branch->code }} — {{ $branch->name }}</p>
            @endif
        </div>
        <a href="{{ route('ops.scan') }}" wire:navigate class="ot-btn ot-btn-secondary shrink-0" aria-label="{{ __('Scan') }}">
            @svg('ot-barcode', 'size-5')
            <span class="sr-only sm:not-sr-only">{{ __('Scan') }}</span>
        </a>
    </header>

    {{-- Search --}}
    <div role="search">
        <label for="stock-search" class="sr-only">{{ __('Search stock') }}</label>
        <div class="relative">
            <span class="pointer-events-none absolute inset-y-0 start-3 flex items-center text-muted-fg">@svg('heroicon-o-magnifying-glass', 'size-5')</span>
            <input id="stock-search" type="search" wire:model.live.debounce.400ms="search" data-western-digits
                   class="ot-input ps-11" autocomplete="off" placeholder="{{ __('SKU, name or barcode') }}">
        </div>
    </div>

    {{-- Expiry levels + low stock as filter chips (label + count, never color alone) --}}
    <div class="-mx-4 overflow-x-auto px-4" aria-label="{{ __('Filters') }}">
        <div class="flex w-max gap-2 pb-1">
            @foreach ($levels as $level)
                @php($count = $counts[$level->id] ?? 0)
                <button type="button" wire:click="setLevel({{ $level->id }})"
                        aria-pressed="{{ $activeLevel?->id === $level->id ? 'true' : 'false' }}"
                        @class([
                            'inline-flex min-h-11 items-center gap-2 rounded-full border px-3 text-sm font-semibold',
                            'border-fg ring-2 ring-fg' => $activeLevel?->id === $level->id,
                            'border-border-strong bg-card' => $activeLevel?->id !== $level->id,
                        ])>
                    <span class="size-3 shrink-0 rounded-full" style="background-color: {{ $level->color }}"></span>
                    <span>{{ $level->name }}</span>
                    <span class="rounded-full bg-subtle px-2 text-xs"><x-num :value="$count" /></span>
                </button>
            @endforeach
            <button type="button" wire:click="toggleLow" aria-pressed="{{ $low ? 'true' : 'false' }}"
                    @class([
                        'inline-flex min-h-11 items-center gap-2 rounded-full border px-3 text-sm font-semibold',
                        'border-danger bg-danger-soft text-danger ring-2 ring-danger' => $low,
                        'border-border-strong bg-card' => ! $low,
                    ])>
                @svg('heroicon-o-arrow-trending-down', 'size-4')
                <span>{{ __('Below minimum') }}</span>
                <span class="rounded-full bg-subtle px-2 text-xs text-fg"><x-num :value="$lowCount" /></span>
            </button>
        </div>
    </div>

    <div wire:loading.delay class="space-y-3" aria-hidden="true">
        @for ($i = 0; $i < 3; $i++)
            <div class="ot-card h-24 animate-pulse bg-subtle motion-reduce:animate-none"></div>
        @endfor
    </div>

    <section wire:loading.remove class="space-y-3" aria-live="polite">
        @if ($mode === 'level')
            <p class="text-sm text-muted-fg">
                {{ __('Items in ":level" (:range days), soonest first.', ['level' => $activeLevel->name, 'range' => $activeLevel->rangeLabel()]) }}
            </p>
        @endif

        @forelse ($rows as $row)
            @if ($mode === 'level')
                <a href="{{ $row['url'] }}" wire:navigate class="ot-card flex items-start gap-3 p-4 hover:bg-subtle">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-subtle text-muted-fg">
                        @svg($row['type'] === 'unit' ? 'heroicon-o-finger-print' : 'heroicon-o-square-3-stack-3d', 'size-5')
                    </span>
                    <div class="min-w-0 flex-1 space-y-1">
                        <p class="font-semibold">{{ $row['product']->name }}@if ($row['product']->size) · <bdi class="whitespace-nowrap">{{ $row['product']->size }}</bdi> @endif</p>
                        <p class="text-sm">
                            <x-code :value="$row['code']" class="font-semibold" />
                            @if ($row['type'] === 'unit' && $row['lot'])
                                <span class="text-muted-fg">· {{ __('Lot') }} <x-code :value="$row['lot']" /></span>
                            @endif
                        </p>
                        <x-expiry-chip :date="$row['expiry']" />
                        <p class="text-xs text-muted-fg">{{ $row['where'] }}</p>
                    </div>
                    <span class="shrink-0 text-lg font-bold"><x-num :value="$row['quantity']" /></span>
                </a>
            @else
                @php($product = $row['product'])
                @php($isLow = $row['min'] > 0 && $row['available'] < $row['min'])
                <a href="{{ route('ops.stock.product', $product) }}" wire:navigate class="ot-card flex items-start gap-3 p-4 hover:bg-subtle">
                    <div class="min-w-0 flex-1 space-y-1">
                        <p class="font-semibold">{{ $product->name }}@if ($product->size) · <bdi class="whitespace-nowrap">{{ $product->size }}</bdi> @endif</p>
                        <p class="text-sm text-muted-fg"><x-code :value="$product->sku" /></p>
                        @if ($row['next_expiry'])
                            <x-expiry-chip :date="$row['next_expiry']" compact />
                        @endif
                        @if ($isLow)
                            <x-ops.status-chip :label="__('Below minimum (:min)', ['min' => \App\Support\Locale\Display::number($row['min'])])" color="danger" icon="heroicon-o-arrow-trending-down" />
                        @endif
                    </div>
                    <div class="shrink-0 text-end">
                        <p @class(['text-2xl font-bold leading-none', 'text-danger' => $isLow])><x-num :value="$row['available']" /></p>
                        <p class="mt-1 text-xs text-muted-fg">{{ __('on shelf') }}</p>
                        @if ($row['total'] !== $row['available'])
                            <p class="text-xs text-muted-fg">{{ __(':count in total', ['count' => \App\Support\Locale\Display::number($row['total'])]) }}</p>
                        @endif
                    </div>
                </a>
            @endif
        @empty
            <div class="ot-card">
                @if ($mode === 'low')
                    <x-ops.empty-state icon="heroicon-o-check-circle" :title="__('Nothing is below its minimum')" />
                @elseif ($mode === 'level')
                    <x-ops.empty-state icon="heroicon-o-check-circle" :title="__('No items in this level')" />
                @else
                    <x-ops.empty-state icon="heroicon-o-archive-box" :title="$search !== '' ? __('No stock matches your search') : __('No stock in this station yet')"
                                       :description="__('Scan a code or try another search.')" />
                @endif
            </div>
        @endforelse

        @if ($hasMore)
            <button type="button" wire:click="loadMore" wire:loading.attr="disabled" class="ot-btn ot-btn-secondary w-full">{{ __('Show more') }}</button>
        @endif
    </section>
</div>
