<div class="space-y-5">
    <a href="{{ route('ops.tasks') }}" wire:navigate class="inline-flex min-h-11 items-center gap-1 text-sm font-semibold text-primary">
        @svg('heroicon-o-chevron-left', 'size-5 rtl:rotate-180')
        <span>{{ __('Tasks') }}</span>
    </a>

    <header class="space-y-2">
        <p class="text-xs font-semibold uppercase tracking-wide text-muted-fg">{{ __('Stock request') }}</p>
        <h1 class="text-2xl font-bold"><x-code :value="$stockRequest->number" /></h1>
        <div class="flex flex-wrap items-center gap-2">
            <x-ops.status-chip :label="$stockRequest->status->getLabel()" :color="$stockRequest->status->getColor()" :icon="$stockRequest->status->getIcon()" />
            @if ($stockRequest->priority === \App\Enums\RequestPriority::Urgent)
                <x-ops.status-chip :label="$stockRequest->priority->getLabel()" color="danger" icon="heroicon-o-bolt" />
            @endif
        </div>
        <p class="text-sm text-muted-fg">
            {{ __('For :branch', ['branch' => $stockRequest->requestingBranch->code]) }} · {{ $stockRequest->requester->name }} · <x-date :value="$stockRequest->created_at" />
            @if ($stockRequest->needed_by) · {{ __('needed by') }} <x-date :value="$stockRequest->needed_by" /> @endif
        </p>
        @if ($stockRequest->notes)
            <p class="text-sm">{{ $stockRequest->notes }}</p>
        @endif
        @if ($stockRequest->review_note)
            <p class="rounded-lg bg-subtle p-3 text-sm"><span class="font-semibold">{{ $stockRequest->reviewer?->name }}:</span> {{ $stockRequest->review_note }}</p>
        @endif
    </header>

    <section class="space-y-3">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-muted-fg">{{ __('Products') }}</h2>
        <ul class="ot-card divide-y divide-border">
            @foreach ($stockRequest->items as $item)
                <li class="flex items-center justify-between gap-3 p-4">
                    <div class="min-w-0">
                        <p class="font-semibold">{{ $item->product->name }}@if ($item->product->size) · <bdi class="whitespace-nowrap">{{ $item->product->size }}</bdi> @endif</p>
                        <p class="text-sm text-muted-fg"><x-code :value="$item->product->sku" />
                            @if ($item->sourceBranch) · {{ __('from :branch', ['branch' => $item->sourceBranch->code]) }} @endif
                        </p>
                    </div>
                    <div class="shrink-0 text-end">
                        <p class="text-lg font-bold"><x-num :value="$item->quantity_approved ?? $item->quantity_requested" /></p>
                        @if ($item->quantity_approved !== null && $item->quantity_approved !== $item->quantity_requested)
                            <p class="text-xs text-warning">{{ __('asked :qty', ['qty' => \App\Support\Locale\Display::number($item->quantity_requested)]) }}</p>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </section>

    @if ($stockRequest->transfers->isNotEmpty())
        <section class="space-y-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-muted-fg">{{ __('Transfers') }}</h2>
            @foreach ($stockRequest->transfers as $transfer)
                <a href="{{ route('ops.transfers.show', $transfer) }}" wire:navigate class="ot-card flex items-center justify-between gap-3 p-4 hover:bg-subtle">
                    <span><x-code :value="$transfer->number" class="font-semibold" /> <span class="text-sm text-muted-fg">{{ __('From :branch', ['branch' => $transfer->fromBranch->code]) }}</span></span>
                    <x-ops.status-chip :label="$transfer->status->getLabel()" :color="$transfer->status->getColor()" :icon="$transfer->status->getIcon()" />
                </a>
            @endforeach
        </section>
    @endif

    @if ($canCancel)
        <button type="button" wire:click="cancel" wire:confirm="{{ __('Cancel this request?') }}" class="ot-btn ot-btn-secondary w-full">{{ __('Cancel request') }}</button>
    @endif
</div>
