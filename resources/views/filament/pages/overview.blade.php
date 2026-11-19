@php
    use App\Enums\Governorate;
    use App\Enums\OverviewMetric;
    use App\Enums\Specialty;

    /** The button whose colour and number fill the city tiles. */
    $primary = $theMetric ?? (collect($metrics)->first(fn ($m) => $m === OverviewMetric::Stock) ?? $metrics[0]);
    $tileSpecialty = $theMetric === null ? $theFocus : null;
    $chosenSpecialty = $theSpecialty ?? $theFocus;
@endphp

<x-filament-panels::page>
    <div class="flex flex-col gap-6">
        {{-- Where we are, and the way back. --}}
        <div class="flex flex-wrap items-center justify-between gap-3">
            <nav class="flex flex-wrap items-center gap-2 text-sm" aria-label="{{ __('Overview') }}">
                <a href="{{ \App\Filament\Admin\Pages\Dashboard::getUrl() }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">{{ __('Main page') }}</a>
                <span class="text-gray-400" aria-hidden="true">/</span>
                @if ($theCity)
                    <button type="button" wire:click="$set('city', null)" class="font-medium text-primary-600 hover:underline dark:text-primary-400">{{ $this->getTitle() }}</button>
                    <span class="text-gray-400" aria-hidden="true">/</span>
                    <span class="text-gray-500 dark:text-gray-400">{{ Governorate::regionLabel($theCity->region()) }}</span>
                    <span class="text-gray-400" aria-hidden="true">/</span>
                    @if ($theSpecialty)
                        <button type="button" wire:click="$set('specialty', null)" class="font-medium text-primary-600 hover:underline dark:text-primary-400">{{ $theCity->getLabel() }}</button>
                        <span class="text-gray-400" aria-hidden="true">/</span>
                        <span class="font-semibold">{{ $theSpecialty->getLabel() }}</span>
                    @else
                        <span class="font-semibold">{{ $theCity->getLabel() }}</span>
                    @endif
                @else
                    <span class="font-semibold">{{ $this->getTitle() }}</span>
                @endif
            </nav>

            @if ($usesPeriod)
                <div class="flex flex-wrap items-center gap-2">
                    <div class="inline-flex overflow-hidden rounded-lg ring-1 ring-gray-950/10 dark:ring-white/10" role="group" aria-label="{{ __('Period') }}">
                        @foreach (['today' => __('Today'), 'month' => __('This month'), 'year' => __('This year'), 'all' => __('All time'), 'custom' => __('Dates')] as $key => $label)
                            <button type="button" wire:click="setPeriod('{{ $key }}')" @if ($this->period === $key) aria-pressed="true" @endif
                                    @class([
                                        'min-h-9 px-3 text-sm font-medium',
                                        'bg-primary-600 text-white' => $this->period === $key,
                                        'bg-white text-gray-700 hover:bg-gray-50 dark:bg-white/5 dark:text-gray-200' => $this->period !== $key,
                                    ])>{{ $label }}</button>
                        @endforeach
                    </div>
                    @if ($this->period === 'custom')
                        <input type="date" wire:model.live="from" aria-label="{{ __('From') }}" class="h-9 rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                        <input type="date" wire:model.live="until" aria-label="{{ __('Until') }}" class="h-9 rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5">
                    @endif
                    <span class="text-xs text-gray-500 dark:text-gray-400" dir="ltr">{{ $periodLabel }}</span>
                </div>
            @endif
        </div>

        @if (! $theCity)
            {{-- 1. Iraq, Kurdistan, both. --}}
            @if ($theMetric)
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    @foreach ($regions as $region)
                        <div @class(['rounded-xl p-5 ring-1', 'bg-primary-600 text-white ring-primary-600' => $region === null, 'bg-white ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10' => $region !== null])>
                            <p @class(['text-sm font-medium', 'text-white/80' => $region === null, 'text-gray-500 dark:text-gray-400' => $region !== null])>{{ __('Total in :region', ['region' => Governorate::regionLabel((string) $region)]) }}</p>
                            <p class="mt-2 text-3xl font-bold tabular-nums">{{ $value($theMetric, $region) }}</p>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="overflow-x-auto rounded-xl bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-white/10">
                                <th class="px-4 py-3 text-start font-semibold">{{ $theFocus->getLabel() }}</th>
                                @foreach ($regions as $region)
                                    <th class="px-4 py-3 text-end font-semibold">{{ Governorate::regionLabel((string) $region) }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($metrics as $m)
                                <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                                    <th class="px-4 py-3 text-start font-medium">
                                        <span class="inline-flex items-center gap-2">@svg($m->icon(), 'size-5 text-gray-400') {{ $m->getLabel() }}</span>
                                    </th>
                                    @foreach ($regions as $region)
                                        <td @class(['px-4 py-3 text-end tabular-nums', 'font-bold' => $region === null])>{{ $value($m, $region, null, $theFocus) }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            {{-- 2. The governorates: green = something there, red = nothing. --}}
            @foreach ($governorates as $region => $cities)
                <section class="flex flex-col gap-3">
                    <h2 class="text-base font-semibold">{{ Governorate::regionLabel($region) }}</h2>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                        @foreach ($cities as $g)
                            @php($green = $has($primary, $g, $tileSpecialty))
                            <button type="button" wire:click="openCity('{{ $g->value }}')" data-city="{{ $g->value }}" data-has="{{ $green ? 'yes' : 'no' }}"
                                    @class([
                                        'flex min-h-20 flex-col items-start justify-between gap-1 rounded-xl p-3 text-start ring-1 transition hover:shadow-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600',
                                        'bg-green-50 text-green-900 ring-green-600/30 dark:bg-green-500/10 dark:text-green-200 dark:ring-green-400/30' => $green,
                                        'bg-red-50 text-red-900 ring-red-600/30 dark:bg-red-500/10 dark:text-red-200 dark:ring-red-400/30' => ! $green,
                                    ])>
                                <span class="flex w-full items-center justify-between gap-2">
                                    <span class="font-semibold">{{ $g->getLabel() }}</span>
                                    <span @class(['size-2.5 shrink-0 rounded-full', 'bg-green-600' => $green, 'bg-red-600' => ! $green]) aria-hidden="true"></span>
                                </span>
                                <span class="text-sm tabular-nums opacity-80">{{ $value($primary, null, $g, $tileSpecialty) }}</span>
                                <span class="sr-only">{{ $green ? __('Yes') : __('No') }}</span>
                            </button>
                        @endforeach
                    </div>
                </section>
            @endforeach

            @if ($unassigned->isNotEmpty())
                <p class="rounded-lg bg-warning-50 p-3 text-sm text-warning-800 ring-1 ring-warning-600/20 dark:bg-warning-500/10 dark:text-warning-200">
                    {{ __('These stations have no governorate yet, so they are not counted here: :stations. Set it on the station (Administration → Stations).', ['stations' => $unassigned->pluck('code')->implode(', ')]) }}
                </p>
            @endif
        @elseif ($theMetric && ! $theSpecialty)
            {{-- 3. A governorate: choose the specialty. --}}
            <div class="flex flex-col gap-2">
                <h2 class="text-xl font-bold">{{ $theCity->getLabel() }}</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('All specialties: :value', ['value' => $value($theMetric, null, $theCity)]) }}</p>
            </div>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach (Specialty::cases() as $s)
                    @php($green = $has($theMetric, $theCity, $s))
                    <button type="button" wire:click="openSpecialty('{{ $s->value }}')" data-specialty="{{ $s->value }}"
                            @class([
                                'flex min-h-28 flex-col items-start justify-between gap-2 rounded-xl p-4 text-start ring-1 transition hover:shadow-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600',
                                'bg-green-50 text-green-900 ring-green-600/30 dark:bg-green-500/10 dark:text-green-200 dark:ring-green-400/30' => $green,
                                'bg-white text-gray-700 ring-gray-950/10 dark:bg-gray-900 dark:text-gray-300 dark:ring-white/10' => ! $green,
                            ])>
                        <span class="inline-flex items-center gap-2 text-lg font-semibold">@svg($s->icon(), 'size-6') {{ $s->getLabel() }}</span>
                        <span class="text-2xl font-bold tabular-nums">{{ $value($theMetric, null, $theCity, $s) }}</span>
                    </button>
                @endforeach
            </div>
        @else
            {{-- 4. One governorate and specialty, product by product. --}}
            <div class="flex flex-col gap-1">
                <h2 class="text-xl font-bold">{{ $theCity->getLabel() }} · {{ $chosenSpecialty->getLabel() }}</h2>
            </div>

            <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
                @foreach ($columns as $m)
                    <div class="rounded-xl bg-white p-4 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                        <p class="inline-flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">@svg($m->icon(), 'size-4') {{ $m->getLabel() }}</p>
                        <p class="mt-1 text-xl font-bold tabular-nums">{{ $value($m, null, $theCity, $chosenSpecialty) }}</p>
                    </div>
                @endforeach
            </div>

            <div class="overflow-x-auto rounded-xl bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            <th class="px-4 py-3 text-start font-semibold">{{ __('SKU') }}</th>
                            <th class="px-4 py-3 text-start font-semibold">{{ __('Product') }}</th>
                            <th class="px-4 py-3 text-start font-semibold">{{ __('Station') }}</th>
                            @foreach ($columns as $m)
                                <th class="px-4 py-3 text-end font-semibold">{{ $m === OverviewMetric::Stations ? __('Pieces') : $m->getLabel() }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                                <td class="px-4 py-2"><x-code :value="$row['sku']" /></td>
                                <td class="px-4 py-2">{{ $row['product'] }}</td>
                                <td class="px-4 py-2 text-gray-600 dark:text-gray-300">{{ $row['station'] }}</td>
                                @foreach ($columns as $m)
                                    <td class="px-4 py-2 text-end tabular-nums">{{ $row['cells'][$m->value] ?? '—' }}</td>
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ 3 + count($columns) }}" class="px-4 py-10 text-center text-gray-500 dark:text-gray-400">
                                    {{ __('Nothing for :specialty in :city.', ['specialty' => $chosenSpecialty->getLabel(), 'city' => $theCity->getLabel()]) }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif

        <p class="text-xs text-gray-500 dark:text-gray-400">
            {{ __('Money is in USD at the rate of each purchase or sale. Stations, stock and value are as of now; sales and profit follow the period.') }}
        </p>
    </div>
</x-filament-panels::page>
