<div class="space-y-6">
    <header>
        <h1 class="text-2xl font-bold">{{ __('Tasks') }}</h1>
        @if ($branch)
            <p class="mt-1 text-muted-fg">{{ $branch->code }} — {{ $branch->name }}</p>
        @endif
    </header>

    {{-- Start something --}}
    <section aria-label="{{ __('New') }}" class="grid grid-cols-2 gap-3">
        @if ($canSurgery)
            <a href="{{ route('ops.surgeries.create') }}" class="ot-card flex min-h-20 flex-col justify-between gap-2 p-4 hover:bg-subtle">
                @svg('heroicon-o-heart', 'size-7 text-primary')
                <span class="font-semibold">{{ __('New surgery') }}</span>
            </a>
        @endif
        @if ($canSurgeries)
            <a href="{{ route('ops.surgeries.index') }}" wire:navigate class="ot-card flex min-h-20 flex-col justify-between gap-2 p-4 hover:bg-subtle">
                @svg('heroicon-o-clipboard-document-list', 'size-7 text-primary')
                <span class="font-semibold">{{ __('My surgeries') }}</span>
            </a>
        @endif
        @if ($canKits)
            <a href="{{ route('ops.kits.index') }}" wire:navigate class="ot-card flex min-h-20 flex-col justify-between gap-2 p-4 hover:bg-subtle">
                @svg('heroicon-o-briefcase', 'size-7 text-primary')
                <span class="font-semibold">{{ __('Kits') }}</span>
            </a>
        @endif
        @if ($canCreateTransfer)
            <a href="{{ route('ops.transfers.create') }}" wire:navigate class="ot-card flex min-h-20 flex-col justify-between gap-2 p-4 hover:bg-subtle">
                @svg('heroicon-o-truck', 'size-7 text-primary')
                <span class="font-semibold">{{ __('New transfer') }}</span>
            </a>
        @endif
        @if ($canRequest)
            <a href="{{ route('ops.requests.create') }}" wire:navigate class="ot-card flex min-h-20 flex-col justify-between gap-2 p-4 hover:bg-subtle">
                @svg('heroicon-o-inbox-arrow-down', 'size-7 text-primary')
                <span class="font-semibold">{{ __('Request stock') }}</span>
            </a>
        @endif
        @if ($canCount)
            <a href="{{ route('ops.counts.create') }}" wire:navigate class="ot-card flex min-h-20 flex-col justify-between gap-2 p-4 hover:bg-subtle">
                @svg('heroicon-o-clipboard-document-check', 'size-7 text-primary')
                <span class="font-semibold">{{ __('Count stock') }}</span>
            </a>
        @endif
        @if ($canWriteOff)
            <a href="{{ route('ops.write-offs.create') }}" wire:navigate class="ot-card flex min-h-20 flex-col justify-between gap-2 p-4 hover:bg-subtle">
                @svg('heroicon-o-trash', 'size-7 text-primary')
                <span class="font-semibold">{{ __('Report damaged / lost') }}</span>
            </a>
        @endif
        @if ($canReturn)
            <a href="{{ route('ops.returns.create') }}" wire:navigate class="ot-card flex min-h-20 flex-col justify-between gap-2 p-4 hover:bg-subtle">
                @svg('heroicon-o-arrow-uturn-left', 'size-7 text-primary')
                <span class="font-semibold">{{ __('Customer return') }}</span>
            </a>
        @endif
    </section>

    @if ($surgeryDrafts->isNotEmpty())
        <section class="space-y-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-muted-fg">{{ __('Surgery drafts to finish') }}</h2>
            @foreach ($surgeryDrafts as $surgery)
                <a href="{{ route('ops.surgeries.show', $surgery) }}" wire:navigate wire:key="sd-{{ $surgery->id }}" class="ot-card flex items-center justify-between gap-3 p-4 hover:bg-subtle">
                    <div class="min-w-0 space-y-1">
                        <x-code :value="$surgery->number" class="font-semibold" />
                        <p class="text-sm text-muted-fg"><x-date :value="$surgery->surgery_date" /></p>
                    </div>
                    <x-ops.status-chip :label="$surgery->status->getLabel()" :color="$surgery->status->getColor()" :icon="$surgery->status->getIcon()" class="shrink-0" />
                </a>
            @endforeach
        </section>
    @endif

    @if ($kitsOut->isNotEmpty())
        <section class="space-y-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-muted-fg">{{ __('Kits out at hospitals') }}</h2>
            @foreach ($kitsOut as $kit)
                <a href="{{ route('ops.kits.show', $kit) }}" wire:navigate wire:key="ko-{{ $kit->id }}" class="ot-card flex items-center justify-between gap-3 p-4 hover:bg-subtle">
                    <div class="min-w-0 space-y-1">
                        <x-code :value="$kit->code" class="font-semibold" />
                        <p class="text-sm text-muted-fg">{{ $kit->customer?->code }}</p>
                    </div>
                    @if ($kit->isOverdue())
                        <x-ops.status-chip :label="__('Overdue')" color="danger" icon="heroicon-m-clock" class="shrink-0" />
                    @else
                        <x-ops.status-chip :label="$kit->status->getLabel()" :color="$kit->status->getColor()" :icon="$kit->status->getIcon()" class="shrink-0" />
                    @endif
                </a>
            @endforeach
        </section>
    @endif

    @php($sections = [
        ['title' => __('To receive'), 'items' => $incoming, 'empty' => __('Nothing on its way to this branch.'), 'kind' => 'incoming'],
        ['title' => __('To prepare and send'), 'items' => $toSend, 'empty' => null, 'kind' => 'draft'],
        ['title' => __('Sent, on the way'), 'items' => $outgoing, 'empty' => null, 'kind' => 'outgoing'],
    ])

    @foreach ($sections as $section)
        @if ($section['items']->isNotEmpty() || $section['empty'])
            <section class="space-y-3">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-muted-fg">{{ $section['title'] }}</h2>
                @forelse ($section['items'] as $transfer)
                    <a href="{{ route('ops.transfers.show', $transfer) }}" wire:navigate wire:key="t-{{ $transfer->id }}"
                       class="ot-card flex items-center justify-between gap-3 p-4 hover:bg-subtle">
                        <div class="min-w-0 space-y-1">
                            <x-code :value="$transfer->number" class="font-semibold" />
                            <p class="text-sm text-muted-fg">
                                @if ($section['kind'] === 'incoming')
                                    {{ __('From :branch', ['branch' => $transfer->fromBranch->code]) }}
                                @else
                                    {{ __('To :branch', ['branch' => $transfer->toBranch->code]) }}
                                @endif
                                · {{ trans_choice(':count line|:count lines', $transfer->items_count, ['count' => \App\Support\Locale\Display::number($transfer->items_count)]) }}
                            </p>
                        </div>
                        <x-ops.status-chip :label="$transfer->status->getLabel()" :color="$transfer->status->getColor()" :icon="$transfer->status->getIcon()" class="shrink-0" />
                    </a>
                @empty
                    <div class="ot-card"><x-ops.empty-state icon="heroicon-o-truck" :title="$section['empty']" /></div>
                @endforelse
            </section>
        @endif
    @endforeach

    @if ($requests->isNotEmpty())
        <section class="space-y-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-muted-fg">{{ __('Stock requests') }}</h2>
            @foreach ($requests as $request)
                <a href="{{ route('ops.requests.show', $request) }}" wire:navigate wire:key="r-{{ $request->id }}" class="ot-card flex items-center justify-between gap-3 p-4 hover:bg-subtle">
                    <div class="min-w-0 space-y-1">
                        <x-code :value="$request->number" class="font-semibold" />
                        <p class="text-sm text-muted-fg">{{ trans_choice(':count product|:count products', $request->items_count, ['count' => \App\Support\Locale\Display::number($request->items_count)]) }}</p>
                    </div>
                    <x-ops.status-chip :label="$request->status->getLabel()" :color="$request->status->getColor()" :icon="$request->status->getIcon()" class="shrink-0" />
                </a>
            @endforeach
        </section>
    @endif

    @if ($counts->isNotEmpty())
        <section class="space-y-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-muted-fg">{{ __('Stock counts') }}</h2>
            @foreach ($counts as $count)
                <a href="{{ route('ops.counts.show', $count) }}" wire:navigate wire:key="c-{{ $count->id }}" class="ot-card flex items-center justify-between gap-3 p-4 hover:bg-subtle">
                    <div class="min-w-0 space-y-1">
                        <x-code :value="$count->number" class="font-semibold" />
                        <p class="text-sm text-muted-fg">{{ $count->scopeLabel() }}</p>
                    </div>
                    <x-ops.status-chip :label="$count->status->getLabel()" :color="$count->status->getColor()" :icon="$count->status->getIcon()" class="shrink-0" />
                </a>
            @endforeach
        </section>
    @endif

    @if ($writeOffs->isNotEmpty())
        <section class="space-y-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-muted-fg">{{ __('Write-offs waiting for approval') }}</h2>
            @foreach ($writeOffs as $writeOff)
                <div wire:key="w-{{ $writeOff->id }}" class="ot-card flex items-center justify-between gap-3 p-4">
                    <div class="min-w-0 space-y-1">
                        <x-code :value="$writeOff->number" class="font-semibold" />
                        <p class="text-sm text-muted-fg">{{ $writeOff->reason->getLabel() }} · {{ trans_choice(':count line|:count lines', $writeOff->items_count, ['count' => \App\Support\Locale\Display::number($writeOff->items_count)]) }}</p>
                    </div>
                    <x-ops.status-chip :label="$writeOff->status->getLabel()" :color="$writeOff->status->getColor()" :icon="$writeOff->status->getIcon()" class="shrink-0" />
                </div>
            @endforeach
        </section>
    @endif
</div>
