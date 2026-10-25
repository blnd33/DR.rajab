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
        <p class="mt-1 flex flex-wrap items-center gap-2 text-muted-fg">
            @if ($role)
                <x-ops.status-chip :label="$role->getLabel()" color="primary" icon="heroicon-o-user-circle" />
            @endif
            @if ($branch)
                <x-ops.status-chip :label="$branch->code.' — '.$branch->name" icon="heroicon-o-building-office" />
            @else
                <x-ops.status-chip :label="__('No branch assigned')" color="warning" icon="heroicon-o-exclamation-triangle" />
            @endif
        </p>
    </section>

    <a href="{{ route('ops.scan') }}" wire:navigate
       class="ot-btn ot-btn-primary w-full gap-3 py-5 text-lg">
        @svg('heroicon-o-qr-code', 'size-7')
        <span>{{ __('Scan anything') }}</span>
    </a>

    <section aria-labelledby="quick-actions" class="space-y-3">
        <h2 id="quick-actions" class="text-sm font-semibold uppercase tracking-wide text-muted-fg">{{ __('Quick actions') }}</h2>
        <div class="grid grid-cols-2 gap-3">
            <a href="{{ route('ops.notifications') }}" wire:navigate class="ot-card flex min-h-24 flex-col justify-between p-4 hover:bg-subtle">
                @svg('heroicon-o-bell', 'size-7 text-primary')
                <span class="font-semibold">{{ __('Notifications') }}</span>
            </a>
            <a href="{{ route('ops.profile') }}" wire:navigate class="ot-card flex min-h-24 flex-col justify-between p-4 hover:bg-subtle">
                @svg('heroicon-o-language', 'size-7 text-primary')
                <span class="font-semibold">{{ __('Language & devices') }}</span>
            </a>
            @if ($canOpenAdmin)
                <a href="{{ url('/admin') }}" class="ot-card col-span-2 flex min-h-16 items-center gap-3 p-4 hover:bg-subtle">
                    @svg('heroicon-o-squares-2x2', 'size-7 text-primary')
                    <span class="font-semibold">{{ __('Open admin panel') }}</span>
                </a>
            @endif
        </div>
    </section>

    <section aria-labelledby="recent" class="space-y-3">
        <h2 id="recent" class="text-sm font-semibold uppercase tracking-wide text-muted-fg">{{ __('Latest notifications') }}</h2>
        @forelse ($recent as $notification)
            <div class="ot-card flex items-start gap-3 p-4">
                @svg('heroicon-o-bell', 'mt-0.5 size-5 shrink-0 text-muted-fg')
                <div class="min-w-0 flex-1">
                    <p class="font-medium">{{ $notification->data['title'] ?? __('Notification') }}</p>
                    @if (! empty($notification->data['body']))
                        <p class="text-sm text-muted-fg">{{ $notification->data['body'] }}</p>
                    @endif
                    <x-date class="text-xs text-muted-fg" :value="$notification->created_at" time />
                </div>
            </div>
        @empty
            <div class="ot-card">
                <x-ops.empty-state icon="heroicon-o-bell-slash" :title="__('No notifications yet')" :description="__('Transfers, approvals and alerts will appear here.')" />
            </div>
        @endforelse
    </section>
</div>
