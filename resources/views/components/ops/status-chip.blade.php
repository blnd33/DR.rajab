{{-- Status = icon + text + color (never color alone). --}}
@props(['color' => 'gray', 'icon' => null, 'label'])

@php
    $classes = match ($color) {
        'success' => 'bg-success-soft text-success ring-success/30',
        'warning' => 'bg-warning-soft text-warning ring-warning/30',
        'danger' => 'bg-danger-soft text-danger ring-danger/30',
        'info' => 'bg-info-soft text-info ring-info/30',
        'primary' => 'bg-primary-soft text-primary ring-primary/30',
        default => 'bg-subtle text-muted-fg ring-border-strong',
    };
@endphp

<span {{ $attributes->class(['inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset', $classes]) }}>
    @if ($icon)
        @svg($icon, 'size-4 shrink-0')
    @endif
    <span>{{ $label }}</span>
</span>
