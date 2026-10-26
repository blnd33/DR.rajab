<div class="space-y-5">
    <a href="{{ route('ops.stock.index') }}" wire:navigate class="inline-flex min-h-11 items-center gap-1 text-sm font-semibold text-primary">
        @svg('heroicon-o-chevron-left', 'size-5 rtl:rotate-180')
        <span>{{ __('Stock') }}</span>
    </a>

    <header class="space-y-1">
        <h1 class="text-2xl font-bold">{{ $product->name }}</h1>
        <p class="text-muted-fg">
            <x-code :value="$product->sku" class="font-semibold text-fg" />
            @if ($product->size) · <bdi class="whitespace-nowrap">{{ $product->size }}</bdi> @endif
            · {{ $product->tracking_type->getLabel() }}
        </p>
    </header>

    <section class="grid grid-cols-2 gap-3" aria-label="{{ __('Quantities') }}">
        <div class="ot-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted-fg">{{ __('On shelf') }}{{ $branch ? ' · '.$branch->code : '' }}</p>
            <p @class(['mt-1 text-3xl font-bold', 'text-danger' => $min > 0 && $onShelf < $min])><x-num :value="$onShelf" /></p>
        </div>
        <div class="ot-card p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted-fg">{{ __('Minimum') }}</p>
            <p class="mt-1 text-3xl font-bold"><x-num :value="$min" /></p>
            @if ($min > 0 && $onShelf < $min)
                <x-ops.status-chip :label="__('Below minimum')" color="danger" icon="heroicon-o-arrow-trending-down" class="mt-1" />
            @endif
        </div>
    </section>

    @if ($next)
        <section class="ot-card space-y-2 border-s-4 border-s-primary p-4" aria-labelledby="fefo-title">
            <h2 id="fefo-title" class="flex items-center gap-2 font-semibold text-primary">
                @svg('heroicon-o-arrow-down-circle', 'size-5')
                <span>{{ __('Use first (FEFO)') }}</span>
            </h2>
            <p><x-code :value="$next instanceof \App\Models\StockUnit ? $next->serial : ($next->lot ?? '—')" class="text-lg font-bold" /></p>
            <x-expiry-chip :date="$next->expiry_date" />
            <p class="text-sm text-muted-fg">{{ $next->locationLabel() }}</p>
        </section>
    @endif

    <section class="space-y-3" aria-labelledby="items-title">
        <h2 id="items-title" class="text-sm font-semibold uppercase tracking-wide text-muted-fg">
            {{ $product->isSerialTracked() ? __('Units in this branch') : __('Lots in this branch') }}
        </h2>

        @if ($product->isSerialTracked())
            @forelse ($units as $unit)
                <a href="{{ route('ops.stock.unit', $unit) }}" wire:navigate wire:key="u-{{ $unit->id }}" class="ot-card flex items-start justify-between gap-3 p-4 hover:bg-subtle">
                    <div class="min-w-0 space-y-1">
                        <x-code :value="$unit->serial" class="block font-semibold" />
                        @if ($unit->lot)
                            <p class="text-sm text-muted-fg">{{ __('Lot') }} <x-code :value="$unit->lot" /></p>
                        @endif
                        <x-expiry-chip :date="$unit->expiry_date" />
                        <p class="text-xs text-muted-fg">{{ $unit->locationLabel() }}</p>
                    </div>
                    <x-ops.status-chip :label="$unit->status->getLabel()" :color="$unit->status->getColor()" :icon="$unit->status->getIcon()" class="shrink-0" />
                </a>
            @empty
                <div class="ot-card"><x-ops.empty-state icon="heroicon-o-archive-box" :title="__('None in this branch')" /></div>
            @endforelse
        @else
            @forelse ($batches as $batch)
                <div wire:key="b-{{ $batch->id }}" class="ot-card flex items-start justify-between gap-3 p-4">
                    <div class="min-w-0 space-y-1">
                        <p><span class="text-sm text-muted-fg">{{ __('Lot') }}</span> <x-code :value="$batch->lot ?? '—'" class="font-semibold" /></p>
                        <x-expiry-chip :date="$batch->expiry_date" />
                        <p class="text-xs text-muted-fg">{{ $batch->locationLabel() }}</p>
                    </div>
                    <p class="shrink-0 text-2xl font-bold"><x-num :value="$batch->quantity" /></p>
                </div>
            @empty
                <div class="ot-card"><x-ops.empty-state icon="heroicon-o-archive-box" :title="__('None in this branch')" /></div>
            @endforelse
        @endif
    </section>

    @if ($elsewhere->isNotEmpty())
        <section class="space-y-3" aria-labelledby="elsewhere-title">
            <h2 id="elsewhere-title" class="text-sm font-semibold uppercase tracking-wide text-muted-fg">{{ __('Other branches') }}</h2>
            <ul class="ot-card divide-y divide-border">
                @foreach ($elsewhere as $row)
                    <li class="flex items-center justify-between gap-3 p-4">
                        <span class="font-semibold">{{ $row->branch->code }} <span class="font-normal text-muted-fg">— {{ $row->branch->name }}</span></span>
                        <span class="text-end">
                            <span class="text-lg font-bold"><x-num :value="$row->qty_available" /></span>
                            <span class="block text-xs text-muted-fg">{{ __('on shelf') }}</span>
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
