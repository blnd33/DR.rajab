<div class="space-y-4">
    <header class="flex items-center justify-between gap-3">
        <h1 class="text-2xl font-bold">{{ __('Notifications') }}</h1>
        @if ($unread > 0)
            <button type="button" wire:click="markAllAsRead" wire:loading.attr="disabled" class="ot-btn ot-btn-secondary text-sm">
                {{ __('Mark all as read') }}
            </button>
        @endif
    </header>

    @forelse ($notifications as $notification)
        <article wire:key="n-{{ $notification->id }}"
                 @class(['ot-card flex items-start gap-3 p-4', 'border-s-4 border-s-primary' => $notification->read_at === null])>
            @svg($notification->read_at ? 'heroicon-o-bell' : 'heroicon-o-bell-alert', 'mt-0.5 size-5 shrink-0 '.($notification->read_at ? 'text-muted-fg' : 'text-primary'))
            <div class="min-w-0 flex-1 space-y-1">
                <p class="font-semibold">{{ $notification->data['title'] ?? __('Notification') }}</p>
                @if (! empty($notification->data['body']))
                    <p class="text-sm text-muted-fg">{{ $notification->data['body'] }}</p>
                @endif
                <div class="flex flex-wrap items-center gap-3">
                    <x-date class="text-xs text-muted-fg" :value="$notification->created_at" time />
                    @if (! empty($notification->data['url']))
                        <a href="{{ $notification->data['url'] }}" class="text-sm font-semibold text-primary">{{ __('Open') }}</a>
                    @endif
                    @if ($notification->read_at === null)
                        <button type="button" wire:click="markAsRead('{{ $notification->id }}')" class="text-sm font-medium text-muted-fg underline-offset-2 hover:underline">
                            {{ __('Mark as read') }}
                        </button>
                    @endif
                </div>
            </div>
        </article>
    @empty
        <div class="ot-card">
            <x-ops.empty-state icon="heroicon-o-bell-slash" :title="__('No notifications yet')" />
        </div>
    @endforelse

    {{ $notifications->links() }}
</div>
