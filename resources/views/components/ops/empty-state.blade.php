@props(['icon' => 'heroicon-o-squares-2x2', 'title', 'description' => null])

<div {{ $attributes->class(['flex flex-col items-center gap-2 px-6 py-12 text-center']) }}>
    <span class="flex size-14 items-center justify-center rounded-full bg-subtle text-muted-fg">
        @svg($icon, 'size-7')
    </span>
    <p class="font-semibold">{{ $title }}</p>
    @if ($description)
        <p class="max-w-xs text-sm text-muted-fg">{{ $description }}</p>
    @endif
    {{ $slot }}
</div>
