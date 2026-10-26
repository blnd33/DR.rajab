<a href="{{ route('ops.surgeries.show', $surgery) }}" wire:navigate wire:key="s-{{ $surgery->id }}" class="ot-card flex items-center justify-between gap-3 p-4 hover:bg-subtle">
    <div class="min-w-0 space-y-1">
        <p class="font-semibold">{{ $surgery->type?->name ?? __('Surgery draft') }}</p>
        <p class="text-sm text-muted-fg">
            <x-date :value="$surgery->surgery_date" />
            @if ($surgery->patient) · {{ $surgery->patient->full_name }} @endif
            @if ($surgery->customer) · {{ $surgery->customer->code }} @endif
        </p>
        <p class="text-xs text-muted-fg"><x-code :value="$surgery->number" /> · {{ trans_choice(':count item|:count items', $surgery->live_items_count ?? 0, ['count' => \App\Support\Locale\Display::number($surgery->live_items_count ?? 0)]) }}</p>
        @if ($surgery->problems)
            <p class="text-xs font-semibold text-danger">{{ trans_choice(':count problem|:count problems', count($surgery->problems), ['count' => \App\Support\Locale\Display::number(count($surgery->problems))]) }}</p>
        @endif
    </div>
    <x-ops.status-chip :label="$surgery->status->getLabel()" :color="$surgery->status->getColor()" :icon="$surgery->status->getIcon()" class="shrink-0" />
</a>
