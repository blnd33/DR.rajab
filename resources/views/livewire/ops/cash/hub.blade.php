<div class="space-y-6">
    <header>
        <h1 class="text-2xl font-bold">{{ __('Cash') }}</h1>
        @if ($branch)
            <p class="mt-1 text-muted-fg">{{ $branch->code }} — {{ $branch->name }}</p>
        @endif
    </header>

    {{-- Balances --}}
    <section aria-label="{{ __('Boxes') }}" class="space-y-3">
        @forelse ($boxes as $box)
            @php($closed = in_array($box->id, $closedToday, true))
            <div wire:key="box-{{ $box->id }}" class="ot-card space-y-3 p-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-semibold">{{ $box->name }}</p>
                        <p class="text-sm text-muted-fg">{{ $box->type->getLabel() }}</p>
                    </div>
                    <x-money :value="$box->current_balance" :currency="$box->currency" class="text-xl font-bold" />
                </div>
                @if ($canClose && $box->isCash())
                    @if ($closed)
                        <x-ops.status-chip :label="__('Closed for today')" color="success" icon="heroicon-m-check-circle" />
                    @else
                        <a href="{{ route('ops.cash.close', $box) }}" wire:navigate class="ot-btn ot-btn-secondary w-full">
                            @svg('heroicon-o-scale', 'size-5')
                            <span>{{ __('Close the day') }}</span>
                        </a>
                    @endif
                @endif
            </div>
        @empty
            <div class="ot-card"><x-ops.empty-state icon="heroicon-o-wallet" :title="__('No cash box in this branch')" :description="__('An admin opens one in Finance → Cash boxes.')" /></div>
        @endforelse
    </section>

    {{-- Start something --}}
    @if ($boxes->isNotEmpty() && ($canSell || $canExpense || $canExchange))
        <section aria-label="{{ __('New') }}" class="grid grid-cols-2 gap-3">
            @if ($canSell)
                <a href="{{ route('ops.cash.sale') }}" wire:navigate class="ot-card flex min-h-20 flex-col justify-between gap-2 p-4 hover:bg-subtle">
                    @svg('heroicon-o-shopping-bag', 'size-7 text-primary')
                    <span class="font-semibold">{{ __('New sale') }}</span>
                </a>
            @endif
            @if ($canExpense)
                <a href="{{ route('ops.cash.expense') }}" wire:navigate class="ot-card flex min-h-20 flex-col justify-between gap-2 p-4 hover:bg-subtle">
                    @svg('heroicon-o-receipt-percent', 'size-7 text-primary')
                    <span class="font-semibold">{{ __('Record an expense') }}</span>
                </a>
            @endif
            @if ($canExchange)
                <a href="{{ route('ops.cash.exchange') }}" wire:navigate class="ot-card flex min-h-20 flex-col justify-between gap-2 p-4 hover:bg-subtle">
                    @svg('heroicon-o-arrows-right-left', 'size-7 text-primary')
                    <span class="font-semibold">{{ __('Exchange currency') }}</span>
                </a>
            @endif
        </section>
    @endif

    @if ($incoming->isNotEmpty())
        <section class="space-y-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-muted-fg">{{ __('Money on its way to you') }}</h2>
            @foreach ($incoming as $transfer)
                <div wire:key="in-{{ $transfer->id }}" class="ot-card flex items-center justify-between gap-3 p-4">
                    <div class="min-w-0 space-y-1">
                        <x-money :value="$transfer->amount" :currency="$transfer->currency" class="font-bold" />
                        <p class="text-sm text-muted-fg">{{ __('From :box', ['box' => $transfer->fromBox->label()]) }}</p>
                    </div>
                    <button type="button" wire:click="receive({{ $transfer->id }})" wire:loading.attr="disabled" class="ot-btn ot-btn-primary shrink-0">
                        @svg('heroicon-o-inbox-arrow-down', 'size-5')
                        <span>{{ __('Receive') }}</span>
                    </button>
                </div>
            @endforeach
        </section>
    @endif

    @if ($recent->isNotEmpty())
        <section class="space-y-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-muted-fg">{{ __('Latest money movements') }}</h2>
            <ul class="ot-card divide-y divide-border">
                @foreach ($recent as $tx)
                    <li wire:key="tx-{{ $tx->id }}" class="flex items-center justify-between gap-3 p-4">
                        <div class="min-w-0">
                            <p class="font-semibold">{{ $tx->type->getLabel() }}</p>
                            <p class="truncate text-sm text-muted-fg">{{ $tx->description ?: $tx->cashBox->name }}</p>
                        </div>
                        <x-money :value="$tx->amount" :currency="$tx->cashBox->currency" :class="'shrink-0 font-semibold '.(str_starts_with((string) $tx->amount, '-') ? 'text-danger' : 'text-success')" />
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
