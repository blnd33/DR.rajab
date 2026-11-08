{{-- Shown on every page of a test site (ORTHOTRACK_TEST_SITE=true) so nobody mistakes it for the real one. --}}
@if (config('orthotrack.test_site'))
    <div {{ $attributes->class(['flex items-center justify-center gap-2 border-b border-warning/30 bg-warning-soft px-4 py-1.5 text-center text-xs font-semibold text-warning']) }} role="note">
        @svg('heroicon-m-beaker', 'size-4 shrink-0', ['aria-hidden' => 'true'])
        <span>{{ __('Test site — for trying things out. The data here is not real and can be erased.') }}</span>
    </div>
@endif
