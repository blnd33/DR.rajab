<div x-data="scanner({ method: 'handleScan' })" class="space-y-5">
    <header>
        <h1 class="text-2xl font-bold">{{ __('Recall search') }}</h1>
    </header>

    <div role="search" class="ot-card space-y-2 p-4">
        <label for="recall-lot" class="block text-sm font-semibold">{{ __('Lot number') }}</label>
        <input id="recall-lot" type="search" wire:model.live.debounce.500ms="lot" data-scan-field data-western-digits
               class="ot-input ot-code" dir="ltr" autocomplete="off" autocapitalize="off" spellcheck="false">
    </div>

    @if ($results !== null)
        @php($units = $results['units'])
        @php($batches = $results['batches'])

        <p role="status" class="text-sm font-semibold">
            @if ($units->isEmpty() && $batches->isEmpty())
                {{ __('Nothing found for lot :lot.', ['lot' => $lot]) }}
            @else
                @unless ($results['exact'])
                    {{ __('No exact match — showing lots that contain ":lot".', ['lot' => $lot]) }}
                @endunless
                {{ trans_choice(':count serial unit|:count serial units', $units->count(), ['count' => \App\Support\Locale\Display::number($units->count())]) }}
                · {{ trans_choice(':count lot bucket|:count lot buckets', $batches->count(), ['count' => \App\Support\Locale\Display::number($batches->count())]) }}
            @endif
        </p>

        <ul class="space-y-3">
            @foreach ($units as $unit)
                <li wire:key="ru-{{ $unit->id }}">
                    <a href="{{ route('ops.stock.unit', $unit) }}" wire:navigate class="ot-card flex items-start justify-between gap-3 p-4 hover:bg-subtle">
                        <div class="min-w-0 space-y-1">
                            <x-code :value="$unit->serial" class="block font-semibold" />
                            <p class="text-sm text-muted-fg"><x-code :value="$unit->product->sku" /> · {{ $unit->product->name }}</p>
                            <p class="text-sm">{{ __('Lot') }} <x-code :value="$unit->lot" /></p>
                            <x-expiry-chip :date="$unit->expiry_date" compact />
                            <p class="text-xs text-muted-fg">{{ $unit->branch->code }} · {{ $unit->locationLabel() }}</p>
                        </div>
                        <x-ops.status-chip :label="$unit->status->getLabel()" :color="$unit->status->getColor()" :icon="$unit->status->getIcon()" class="shrink-0" />
                    </a>
                </li>
            @endforeach
            @foreach ($batches as $batch)
                <li wire:key="rb-{{ $batch->id }}" class="ot-card flex items-start justify-between gap-3 p-4">
                    <div class="min-w-0 space-y-1">
                        <p><x-code :value="$batch->product->sku" class="font-semibold" /> <span class="text-sm text-muted-fg">{{ $batch->product->name }}</span></p>
                        <p class="text-sm">{{ __('Lot') }} <x-code :value="$batch->lot" /></p>
                        <x-expiry-chip :date="$batch->expiry_date" compact />
                        <p class="text-xs text-muted-fg">{{ $batch->branch->code }} · {{ $batch->locationLabel() }}</p>
                    </div>
                    <p class="shrink-0 text-2xl font-bold"><x-num :value="$batch->quantity" /></p>
                </li>
            @endforeach
        </ul>
    @endif
</div>
