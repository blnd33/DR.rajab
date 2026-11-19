<div x-data="scanner({ method: 'handleScan', serverFeedback: true })" class="space-y-5">
    <header>
        <h1 class="text-2xl font-bold">{{ __('Instrument sets') }}</h1>
        @if ($branch)
            <p class="mt-1 text-muted-fg">{{ $branch->code }} — {{ $branch->name }}</p>
        @endif
    </header>

    <x-ops.scan-box id="kit-code" :hint="__('Scan an instrument set label to open it.')" />

    <section class="space-y-3" aria-labelledby="kits-title">
        <h2 id="kits-title" class="text-sm font-semibold uppercase tracking-wide text-muted-fg">{{ __('Instrument sets in this station') }}</h2>
        @forelse ($kits as $kit)
            <a href="{{ route('ops.kits.show', $kit) }}" wire:navigate wire:key="k-{{ $kit->id }}" class="ot-card flex items-center justify-between gap-3 p-4 hover:bg-subtle">
                <div class="min-w-0 space-y-1">
                    <x-code :value="$kit->code" class="font-semibold" />
                    <p class="text-sm text-muted-fg">{{ $kit->type->name }}@if ($kit->customer) · {{ $kit->customer->code }}@endif</p>
                    @if ($kit->isOverdue())
                        <p class="text-xs font-semibold text-danger">{{ __('Overdue') }}</p>
                    @endif
                </div>
                <x-ops.status-chip :label="$kit->status->getLabel()" :color="$kit->status->getColor()" :icon="$kit->status->getIcon()" class="shrink-0" />
            </a>
        @empty
            <div class="ot-card"><x-ops.empty-state icon="heroicon-o-briefcase" :title="__('No instrument sets in this station')" /></div>
        @endforelse
    </section>
</div>
