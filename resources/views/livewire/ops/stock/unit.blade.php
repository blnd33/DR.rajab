<div class="space-y-5">
    <a href="{{ route('ops.stock.product', $unit->product) }}" wire:navigate class="inline-flex min-h-11 items-center gap-1 text-sm font-semibold text-primary">
        @svg('heroicon-o-chevron-left', 'size-5 rtl:rotate-180')
        <span>{{ $unit->product->sku }}</span>
    </a>

    <header class="space-y-2">
        <p class="text-xs font-semibold uppercase tracking-wide text-muted-fg">{{ __('Serial unit') }}</p>
        <h1 class="break-all text-2xl font-bold"><x-code :value="$unit->serial" /></h1>
        <p class="text-muted-fg">{{ $unit->product->name }}@if ($unit->product->size) · <bdi>{{ $unit->product->size }}</bdi> @endif</p>
        <x-ops.status-chip :label="$unit->status->getLabel()" :color="$unit->status->getColor()" :icon="$unit->status->getIcon()" />
    </header>

    <dl class="ot-card grid grid-cols-2 gap-x-4 gap-y-3 p-4 text-sm">
        <div><dt class="text-xs text-muted-fg">{{ __('Branch') }}</dt><dd class="font-semibold">{{ $unit->branch->code }}</dd></div>
        <div><dt class="text-xs text-muted-fg">{{ __('Where') }}</dt><dd>{{ $unit->locationLabel() }}</dd></div>
        <div><dt class="text-xs text-muted-fg">{{ __('Lot') }}</dt><dd><x-code :value="$unit->lot ?? '—'" /></dd></div>
        <div><dt class="text-xs text-muted-fg">{{ __('Received') }}</dt><dd><x-date :value="$unit->received_at" /></dd></div>
        <div class="col-span-2"><dt class="text-xs text-muted-fg">{{ __('Expiry') }}</dt><dd><x-expiry-chip :date="$unit->expiry_date" /></dd></div>
        @if ($unit->supplier)
            <div class="col-span-2"><dt class="text-xs text-muted-fg">{{ __('Supplier') }}</dt><dd>{{ $unit->supplier->name }}</dd></div>
        @endif
    </dl>

    <section class="space-y-3" aria-labelledby="history-title">
        <h2 id="history-title" class="text-sm font-semibold uppercase tracking-wide text-muted-fg">{{ __('History') }}</h2>
        <ol class="ot-card space-y-4 p-4">
            @forelse ($movements as $movement)
                <li class="flex gap-3">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-subtle text-muted-fg">@svg($movement->type->getIcon(), 'size-5')</span>
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold">{{ $movement->type->getLabel() }}
                            @if ($text = $history->describe($movement))
                                <span class="font-normal text-muted-fg">· {{ $text }}</span>
                            @endif
                        </p>
                        <p class="text-xs text-muted-fg"><x-date :value="$movement->occurred_at" time /> · {{ $movement->user?->name ?? __('System') }}</p>
                        @if ($movement->reason)
                            <p class="mt-1 text-sm">{{ $movement->reason }}</p>
                        @endif
                        @if ($movement->details)
                            <ul class="mt-1 space-y-0.5 text-xs">
                                @foreach ($movement->details as $field => $change)
                                    <li><span class="font-semibold">{{ \App\Support\FieldLabels::for($field) }}:</span>
                                        <bdi dir="ltr" class="ot-code text-muted-fg line-through">{{ $change['old'] ?? '—' }}</bdi> →
                                        <bdi dir="ltr" class="ot-code">{{ $change['new'] ?? '—' }}</bdi></li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </li>
            @empty
                <li class="text-sm text-muted-fg">{{ __('No movements yet.') }}</li>
            @endforelse
        </ol>
    </section>
</div>
