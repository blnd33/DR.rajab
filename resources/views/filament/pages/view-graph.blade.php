@php
    use App\Services\Graphs\Graph;
    use App\Support\Locale\Display;

    $format = fn (float $value) => $data->currency !== null ? Display::money($value, $data->currency) : Display::number($value, fmod($value, 1.0) === 0.0 ? 0 : 2);
    $single = count($data->series) === 1 && $definition->summable;
    $total = $data->total();
    $timeline = in_array($definition->kind, [Graph::LINE, Graph::MONTHS], true);
@endphp

<x-filament-panels::page>
    <x-filament::section compact>
        <div class="flex flex-col gap-3" aria-label="{{ __('Filters') }}">
            {{ $this->filtersForm }}
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex flex-wrap items-center gap-2" role="group" aria-label="{{ __('Draw as') }}">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('Draw as') }}</span>
                    @foreach ($views as $option)
                        <x-filament::button
                            size="sm"
                            :color="$option['key'] === $view ? 'primary' : 'gray'"
                            :outlined="$option['key'] !== $view"
                            :icon="$option['icon']"
                            :disabled="$option['reason'] !== null"
                            :tooltip="$option['reason']"
                            :aria-pressed="$option['key'] === $view ? 'true' : 'false'"
                            wire:click="chooseView('{{ $option['key'] }}')"
                            wire:key="view-{{ $option['key'] }}"
                        >{{ $option['label'] }}</x-filament::button>
                    @endforeach
                </div>
                <span class="hidden h-6 w-px bg-gray-200 sm:block dark:bg-white/10" aria-hidden="true"></span>
                <x-filament::button color="gray" size="sm" wire:click="resetFilters" icon="heroicon-m-arrow-path">{{ __('Reset') }}</x-filament::button>
                <span wire:loading class="text-sm text-gray-500 dark:text-gray-400">{{ __('Loading…') }}</span>
            </div>
        </div>
    </x-filament::section>

    @if ($data->isEmpty())
        <x-filament::section>
            <div class="flex flex-col items-center gap-2 py-8 text-center">
                @svg($definition->icon(), 'size-10 text-gray-400')
                <p class="font-semibold">{{ __('Nothing to draw for these filters') }}</p>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Try a longer period or another branch.') }}</p>
            </div>
        </x-filament::section>
    @else
        @livewire(\App\Filament\Admin\Graphs\GraphChart::class, [
            'kind' => $chartKind,
            'payload' => ['labels' => $drawn->labels, 'series' => $drawn->series, 'other' => $drawn->other, 'money' => $drawn->currency !== null],
        ], key($chartKey))

        @if ($pieNote !== null || ($views[0]['reason'] !== null && $view !== 'pie'))
            <p class="-mt-4 text-xs text-gray-500 dark:text-gray-400">{{ $pieNote ?? __('Pie not available: :reason', ['reason' => $views[0]['reason']]) }}</p>
        @endif

        <x-filament::section :heading="__('The numbers')" compact>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-start text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th scope="col" class="py-2 pe-3 text-start font-medium">{{ $timeline ? __('Period') : __('Name') }}</th>
                            @foreach ($data->series as $series)
                                <th scope="col" class="px-3 py-2 text-end font-medium">{{ $series['label'] }}{{ $data->currency !== null ? ' ('.$data->currency.')' : '' }}</th>
                            @endforeach
                            @if ($single)
                                <th scope="col" class="ps-3 py-2 text-end font-medium">{{ __('Share') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($data->labels as $i => $label)
                            <tr wire:key="row-{{ $i }}">
                                <th scope="row" class="py-2 pe-3 text-start font-normal"><bdi>{{ $label }}</bdi></th>
                                @foreach ($data->series as $series)
                                    <td class="px-3 py-2 text-end tabular-nums" dir="ltr">{{ $format((float) ($series['values'][$i] ?? 0)) }}</td>
                                @endforeach
                                @if ($single)
                                    <td class="ps-3 py-2 text-end tabular-nums text-gray-500 dark:text-gray-400" dir="ltr">
                                        {{ $total != 0 ? Display::number(($data->values()[$i] ?? 0) / $total * 100, 1).' %' : '—' }}
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                    @if ($definition->summable)
                        <tfoot>
                            <tr class="border-t border-gray-200 font-semibold dark:border-white/10">
                                <th scope="row" class="py-2 pe-3 text-start">{{ __('Total') }}</th>
                                @foreach ($data->series as $s => $series)
                                    <td class="px-3 py-2 text-end tabular-nums" dir="ltr">{{ $format($data->total($s)) }}</td>
                                @endforeach
                                @if ($single)
                                    <td class="ps-3 py-2 text-end tabular-nums" dir="ltr">{{ Display::number(100) }} %</td>
                                @endif
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
            @if ($definition->kind === Graph::RANKING)
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ __('The ten largest are shown; the share is of these ten.') }}</p>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
