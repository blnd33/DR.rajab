<x-filament-panels::page>
    <x-filament::section>
        <form wire:submit="search" class="flex flex-col gap-2 sm:flex-row" role="search">
            <label for="recall-lot" class="sr-only">{{ __('Lot number') }}</label>
            <x-filament::input.wrapper class="flex-1" prefix-icon="heroicon-m-magnifying-glass">
                <x-filament::input id="recall-lot" type="text" wire:model="lot" dir="ltr" class="ot-code" autocomplete="off" autofocus
                                   :placeholder="__('Lot number')" />
            </x-filament::input.wrapper>
            <x-filament::button type="submit" icon="heroicon-m-magnifying-glass" wire:loading.attr="disabled">{{ __('Search') }}</x-filament::button>
        </form>
    </x-filament::section>

    @if ($searched)
        @php($units = $results['units'])
        @php($batches = $results['batches'])

        <div role="status" class="text-sm">
            @if ($units->isEmpty() && $batches->isEmpty())
                <x-filament::section>
                    <p class="font-semibold">{{ __('Nothing found for lot :lot.', ['lot' => $lot]) }}</p>
                </x-filament::section>
            @else
                <p class="font-semibold">
                    @unless ($results['exact'])
                        {{ __('No exact match — showing lots that contain ":lot".', ['lot' => $lot]) }}
                    @endunless
                    {{ trans_choice(':count serial unit|:count serial units', $units->count(), ['count' => \App\Support\Locale\Display::number($units->count())]) }}
                    · {{ trans_choice(':count lot bucket|:count lot buckets', $batches->count(), ['count' => \App\Support\Locale\Display::number($batches->count())]) }}
                </p>
                @if ($statusCounts->isNotEmpty())
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach ($statusCounts as $status => $count)
                            @php($s = \App\Enums\StockStatus::from($status))
                            <x-filament::badge :color="$s->getColor()" :icon="$s->getIcon()">{{ $s->getLabel() }}: {{ \App\Support\Locale\Display::number($count) }}</x-filament::badge>
                        @endforeach
                    </div>
                @endif
            @endif
        </div>

        @if ($units->isNotEmpty())
            <x-filament::section :heading="__('Serial units')">
                <div class="overflow-x-auto">
                    <table class="w-full text-start text-sm">
                        <thead class="text-xs uppercase text-gray-500">
                            <tr>
                                <th class="px-2 py-2 text-start">{{ __('Serial') }}</th>
                                <th class="px-2 py-2 text-start">{{ __('Product') }}</th>
                                <th class="px-2 py-2 text-start">{{ __('Lot') }}</th>
                                <th class="px-2 py-2 text-start">{{ __('Expiry') }}</th>
                                <th class="px-2 py-2 text-start">{{ __('Status') }}</th>
                                <th class="px-2 py-2 text-start">{{ __('Where') }}</th>
                                <th class="px-2 py-2 text-start">{{ __('Supplier') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($units as $unit)
                                <tr>
                                    <td class="px-2 py-2">
                                        <a href="{{ \App\Filament\Admin\Resources\StockUnits\StockUnitResource::getUrl('view', ['record' => $unit]) }}" class="font-semibold text-primary-600 hover:underline">
                                            <x-code :value="$unit->serial" />
                                        </a>
                                    </td>
                                    <td class="px-2 py-2"><x-code :value="$unit->product->sku" /> <span class="text-gray-500">{{ $unit->product->name }}</span></td>
                                    <td class="px-2 py-2"><x-code :value="$unit->lot" /></td>
                                    <td class="px-2 py-2"><x-expiry-chip :date="$unit->expiry_date" /></td>
                                    <td class="px-2 py-2"><x-filament::badge :color="$unit->status->getColor()" :icon="$unit->status->getIcon()">{{ $unit->status->getLabel() }}</x-filament::badge></td>
                                    <td class="px-2 py-2">{{ $unit->branch->code }} · {{ $unit->locationLabel() }}</td>
                                    <td class="px-2 py-2">{{ $unit->supplier?->name ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif

        @if ($batches->isNotEmpty())
            <x-filament::section :heading="__('Lot buckets')">
                <div class="overflow-x-auto">
                    <table class="w-full text-start text-sm">
                        <thead class="text-xs uppercase text-gray-500">
                            <tr>
                                <th class="px-2 py-2 text-start">{{ __('Product') }}</th>
                                <th class="px-2 py-2 text-start">{{ __('Lot') }}</th>
                                <th class="px-2 py-2 text-start">{{ __('Expiry') }}</th>
                                <th class="px-2 py-2 text-end">{{ __('Quantity') }}</th>
                                <th class="px-2 py-2 text-start">{{ __('Where') }}</th>
                                <th class="px-2 py-2 text-start">{{ __('Supplier') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach ($batches as $batch)
                                <tr>
                                    <td class="px-2 py-2"><x-code :value="$batch->product->sku" /> <span class="text-gray-500">{{ $batch->product->name }}</span></td>
                                    <td class="px-2 py-2"><x-code :value="$batch->lot" /></td>
                                    <td class="px-2 py-2"><x-expiry-chip :date="$batch->expiry_date" /></td>
                                    <td class="px-2 py-2 text-end font-semibold"><x-num :value="$batch->quantity" /></td>
                                    <td class="px-2 py-2">{{ $batch->branch->code }} · {{ $batch->locationLabel() }}</td>
                                    <td class="px-2 py-2">{{ $batch->supplier?->name ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
