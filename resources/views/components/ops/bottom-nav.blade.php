{{-- Max 5 items. Scan sits in the middle as the primary action. Later phases add Surgery / Tasks. --}}
@php
    $items = [
        ['route' => 'ops.home', 'label' => __('Home'), 'icon' => 'heroicon-o-home'],
        ['route' => 'ops.scan', 'label' => __('Scan'), 'icon' => 'heroicon-o-qr-code', 'primary' => true],
        ['route' => 'ops.notifications', 'label' => __('Alerts'), 'icon' => 'heroicon-o-bell'],
        ['route' => 'ops.profile', 'label' => __('Me'), 'icon' => 'heroicon-o-user-circle'],
    ];
@endphp

<nav class="ot-safe-bottom fixed inset-x-0 bottom-0 z-30 border-t border-border bg-card" aria-label="{{ __('Main') }}">
    <ul class="mx-auto grid max-w-screen-md grid-cols-4">
        @foreach ($items as $item)
            @php($active = request()->routeIs($item['route']))
            <li>
                <a href="{{ route($item['route']) }}" wire:navigate
                   @if ($active) aria-current="page" @endif
                   @class([
                       'flex min-h-16 flex-col items-center justify-center gap-1 text-xs font-medium',
                       'text-primary' => $active,
                       'text-muted-fg hover:text-fg' => ! $active,
                   ])>
                    @if ($item['primary'] ?? false)
                        <span class="-mt-6 flex size-14 items-center justify-center rounded-full bg-primary text-white shadow-lg ring-4 ring-bg">
                            @svg($item['icon'], 'size-7')
                        </span>
                    @else
                        @svg($item['icon'], 'size-6')
                    @endif
                    <span>{{ $item['label'] }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</nav>
