<div class="space-y-6">
    <section aria-labelledby="greeting">
        @php
            // First name, keeping a leading title with it ("Dr. Ahmad", "د. أحمد").
            $parts = preg_split('/\s+/u', trim($user->name));
            $short = preg_match('/^(dr\.?|د\.?|دکتۆر|الدكتور|prof\.?)$/iu', $parts[0] ?? '') && isset($parts[1])
                ? $parts[0].' '.$parts[1]
                : ($parts[0] ?? $user->name);
        @endphp
        <h1 id="greeting" class="text-2xl font-bold">{{ __('Hello, :name', ['name' => $short]) }}</h1>
        @unless ($branch)
            <p class="mt-1"><x-ops.status-chip :label="__('No station assigned')" color="warning" icon="heroicon-o-exclamation-triangle" /></p>
        @endunless
    </section>

    @if ($canSummary)
        <a href="{{ route('ops.summary') }}" wire:navigate class="ot-card flex items-center gap-3 p-4 hover:bg-subtle">
            @svg('heroicon-o-sun', 'size-8 shrink-0 text-warning')
            <span class="min-w-0 flex-1">
                <span class="block text-sm text-muted-fg">{{ __('Daily Business Report') }}</span>
                <span class="block font-semibold">{{ $summaryLine ?? __('Arrives at :time. Open it now to see the latest numbers.', ['time' => \App\Support\Locale\Display::digits($summaryTime)]) }}</span>
            </span>
            @svg('heroicon-m-chevron-right', 'size-5 shrink-0 text-muted-fg rtl:rotate-180')
        </a>
    @endif

    @if ($canAsk)
        <a href="{{ route('ops.ask') }}" wire:navigate class="ot-card flex items-center gap-3 p-4 hover:bg-subtle">
            @svg('heroicon-o-chat-bubble-left-right', 'size-8 shrink-0 text-primary')
            <span class="min-w-0 flex-1">
                <span class="block font-semibold">{{ __('Ask') }}</span>
                <span class="block text-sm text-muted-fg">{{ __('Instrument sets, stock, sales, any code — answered from the system.') }}</span>
            </span>
            @svg('heroicon-m-chevron-right', 'size-5 shrink-0 text-muted-fg rtl:rotate-180')
        </a>
    @endif

    @if ($clinical)
        {{-- Doctors: one big action, then a few tiles. Scan stays in the bottom bar. --}}
        <a href="{{ route('ops.surgeries.create') }}" wire:navigate class="ot-btn ot-btn-primary w-full gap-3 py-6 text-xl">
            @svg('heroicon-o-plus-circle', 'size-8')
            <span>{{ __('New surgery') }}</span>
        </a>

        <div class="grid grid-cols-2 gap-3">
            <a href="{{ route('ops.surgeries.index') }}" wire:navigate class="ot-card relative flex min-h-28 flex-col justify-between p-4 hover:bg-subtle">
                @svg('heroicon-o-heart', 'size-8 text-primary')
                <span class="text-lg font-semibold">{{ __('My surgeries') }}</span>
                @if ($drafts > 0)
                    <x-ops.status-chip class="absolute end-3 top-3" color="warning" icon="heroicon-m-pencil-square" :label="__('Drafts').': '.\App\Support\Locale\Display::number($drafts)" />
                @endif
            </a>
            @if ($canKits)
                <a href="{{ route('ops.kits.index') }}" wire:navigate class="ot-card flex min-h-28 flex-col justify-between p-4 hover:bg-subtle">
                    @svg('heroicon-o-briefcase', 'size-8 text-primary')
                    <span class="text-lg font-semibold">{{ __('Instrument sets') }}</span>
                </a>
            @endif
            @if ($canReceive)
                <a href="{{ route('ops.stock.receive') }}" wire:navigate class="ot-card flex min-h-28 flex-col justify-between p-4 hover:bg-subtle">
                    @svg('heroicon-o-inbox-arrow-down', 'size-8 text-primary')
                    <span class="text-lg font-semibold">{{ __('Receive stock') }}</span>
                </a>
            @endif
            @if ($canOpenAdmin)
                <a href="{{ url('/admin') }}" class="ot-card col-span-2 flex min-h-16 items-center gap-3 p-4 hover:bg-subtle">
                    @svg('heroicon-o-squares-2x2', 'size-7 text-primary')
                    <span class="font-semibold">{{ __('Open admin panel') }}</span>
                </a>
            @endif
        </div>
    @else
        <a href="{{ route('ops.scan') }}" wire:navigate
           class="ot-btn ot-btn-primary w-full gap-3 py-5 text-lg">
            @svg('ot-barcode', 'size-7')
            <span>{{ __('Scan anything') }}</span>
        </a>

        @if ($canStock)
            <section aria-labelledby="stock-alerts" class="space-y-3">
                <h2 id="stock-alerts" class="text-sm font-semibold uppercase tracking-wide text-muted-fg">{{ __('Stock in this station') }}</h2>
                <div class="grid grid-cols-2 gap-3">
                    @foreach ($expiry as $row)
                        @php($level = $row['level'])
                        <a href="{{ route('ops.stock.index', ['level' => $level->id]) }}" wire:navigate class="ot-card flex min-h-24 flex-col justify-between gap-2 p-4 hover:bg-subtle">
                            <span class="inline-flex w-fit items-center gap-1 whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-semibold" style="background-color: {{ $level->color }}; color: {{ $level->text_color }};">
                                @svg($level->is_expired_level ? 'heroicon-m-no-symbol' : 'heroicon-m-clock', 'size-3.5')
                                {{ $level->name }}
                            </span>
                            <span class="text-3xl font-bold"><x-num :value="$row['total']" /></span>
                        </a>
                    @endforeach
                    <a href="{{ route('ops.stock.index', ['low' => 1]) }}" wire:navigate class="ot-card col-span-2 flex min-h-16 items-center justify-between gap-3 p-4 hover:bg-subtle">
                        <span class="flex items-center gap-3 font-semibold">
                            @svg('heroicon-o-arrow-trending-down', 'size-7 '.($lowStock > 0 ? 'text-danger' : 'text-success'))
                            {{ __('Below minimum') }}
                        </span>
                        <span @class(['text-2xl font-bold', 'text-danger' => $lowStock > 0])><x-num :value="$lowStock" /></span>
                    </a>
                </div>
            </section>
        @endif

        <section aria-labelledby="quick-actions" class="space-y-3">
            <h2 id="quick-actions" class="text-sm font-semibold uppercase tracking-wide text-muted-fg">{{ __('Quick actions') }}</h2>
            <div class="grid grid-cols-2 gap-3">
                @if ($canStock)
                    <a href="{{ route('ops.stock.index') }}" wire:navigate class="ot-card flex min-h-24 flex-col justify-between p-4 hover:bg-subtle">
                        @svg('heroicon-o-archive-box', 'size-7 text-primary')
                        <span class="font-semibold">{{ __('Stock') }}</span>
                    </a>
                @endif
                @if ($canReceive)
                    <a href="{{ route('ops.stock.receive') }}" wire:navigate class="ot-card flex min-h-24 flex-col justify-between p-4 hover:bg-subtle">
                        @svg('heroicon-o-inbox-arrow-down', 'size-7 text-primary')
                        <span class="font-semibold">{{ __('Receive stock') }}</span>
                    </a>
                @endif
                @if ($canRecall)
                    <a href="{{ route('ops.recall') }}" wire:navigate class="ot-card flex min-h-24 flex-col justify-between p-4 hover:bg-subtle">
                        @svg('heroicon-o-shield-exclamation', 'size-7 text-primary')
                        <span class="font-semibold">{{ __('Recall search') }}</span>
                    </a>
                @endif
                @if ($canOpenAdmin)
                    <a href="{{ url('/admin') }}" class="ot-card col-span-2 flex min-h-16 items-center gap-3 p-4 hover:bg-subtle">
                        @svg('heroicon-o-squares-2x2', 'size-7 text-primary')
                        <span class="font-semibold">{{ __('Open admin panel') }}</span>
                    </a>
                @endif
            </div>
        </section>

        @if ($recent->isNotEmpty())
            <section aria-labelledby="recent" class="space-y-3">
                <h2 id="recent" class="text-sm font-semibold uppercase tracking-wide text-muted-fg">{{ __('Latest notifications') }}</h2>
                @foreach ($recent as $notification)
                    <a href="{{ route('ops.notifications') }}" wire:navigate class="ot-card flex items-start gap-3 p-4 hover:bg-subtle">
                        @svg('heroicon-o-bell', 'mt-0.5 size-5 shrink-0 text-muted-fg')
                        <div class="min-w-0 flex-1">
                            <p class="font-medium">{{ $notification->data['title'] ?? __('Notification') }}</p>
                            <x-date class="text-xs text-muted-fg" :value="$notification->created_at" time />
                        </div>
                    </a>
                @endforeach
            </section>
        @endif
    @endif
</div>
