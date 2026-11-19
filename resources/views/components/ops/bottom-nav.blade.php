{{-- Max 5 items. Scan sits in the middle as the primary action; notifications are on the top-bar bell. --}}
@php
    $items = [
        ['route' => 'ops.home', 'label' => __('Home'), 'icon' => 'heroicon-o-home'],
        ['route' => 'ops.stock.index', 'label' => __('Stock'), 'icon' => 'heroicon-o-archive-box', 'match' => 'ops.stock.*'],
        ['route' => 'ops.scan', 'label' => __('Scan'), 'icon' => 'ot-barcode', 'primary' => true],
        ['route' => 'ops.tasks', 'label' => __('Tasks'), 'icon' => 'heroicon-o-clipboard-document-list', 'match' => ['ops.tasks', 'ops.transfers.*', 'ops.requests.*', 'ops.counts.*', 'ops.write-offs.*', 'ops.returns.*', 'ops.surgeries.*', 'ops.kits.*', 'ops.cash', 'ops.cash.*']],
        ['route' => 'ops.profile', 'label' => __('Me'), 'badge' => 'KM'],
    ];
@endphp

<nav class="ot-safe-bottom fixed inset-x-0 bottom-0 z-30 border-t border-border bg-card" aria-label="{{ __('Main') }}">
    <ul class="mx-auto grid max-w-screen-md grid-cols-5">
        @foreach ($items as $item)
            @php($active = request()->routeIs($item['match'] ?? $item['route']))
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
                    @elseif (isset($item['badge']))
                        {{-- Profile: the KM (KurdishMedicus) badge instead of a person icon. --}}
                        <span @class([
                            'flex size-7 items-center justify-center rounded-full text-[0.6875rem] font-bold tracking-wide',
                            'bg-primary text-white' => $active,
                            'bg-subtle text-fg ring-1 ring-border-strong' => ! $active,
                        ]) aria-hidden="true">{{ $item['badge'] }}</span>
                    @else
                        @svg($item['icon'], 'size-6')
                    @endif
                    <span>{{ $item['label'] }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</nav>
