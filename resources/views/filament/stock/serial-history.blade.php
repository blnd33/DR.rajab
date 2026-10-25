{{-- Serial history timeline (stock ledger rows for one unit, newest first). --}}
@php
    $history = app(\App\Services\Stock\SerialHistory::class);
    $movements = $history->for($getRecord());
    $canSeeCost = \App\Filament\Admin\Resources\StockUnits\StockUnitResource::canSeeCost();
@endphp

@if ($movements->isEmpty())
    <p class="text-sm text-gray-500">{{ __('No movements yet.') }}</p>
@else
    <ol class="relative space-y-5 border-s border-gray-200 ps-6 dark:border-white/10">
        @foreach ($movements as $movement)
            <li class="relative">
                <span class="absolute -start-[2.05rem] top-0.5 flex size-6 items-center justify-center rounded-full bg-white ring-2 ring-gray-200 dark:bg-gray-900 dark:ring-white/10">
                    @svg($movement->type->getIcon(), 'size-3.5 text-gray-600 dark:text-gray-300')
                </span>
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <x-filament::badge :color="$movement->type->getColor()">{{ $movement->type->getLabel() }}</x-filament::badge>
                    @if ($text = $history->describe($movement))
                        <span class="text-sm font-medium">{{ $text }}</span>
                    @endif
                    @if ($movement->is_override)
                        <x-filament::badge color="danger" icon="heroicon-m-exclamation-triangle">{{ __('Override') }}</x-filament::badge>
                    @endif
                </div>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    <time dir="ltr">{{ \App\Support\Locale\Display::dateTime($movement->occurred_at) }}</time>
                    · {{ $movement->user?->name ?? __('System') }}
                    @if ($canSeeCost && $movement->unit_cost !== null)
                        · {{ \App\Support\Locale\Display::number($movement->unit_cost, 2) }} {{ $movement->cost_currency }}
                    @endif
                </p>
                @if ($movement->reason)
                    <p class="mt-1 text-sm">{{ $movement->reason }}</p>
                @endif
                @if ($movement->details)
                    <dl class="mt-2 grid gap-1 rounded-lg bg-gray-50 p-2 text-xs dark:bg-white/5">
                        @foreach ($movement->details as $field => $change)
                            <div class="flex flex-wrap gap-x-2">
                                <dt class="font-semibold">{{ \App\Support\FieldLabels::for($field) }}:</dt>
                                <dd><bdi dir="ltr" class="ot-code line-through opacity-70">{{ $change['old'] ?? '—' }}</bdi> → <bdi dir="ltr" class="ot-code">{{ $change['new'] ?? '—' }}</bdi></dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
            </li>
        @endforeach
    </ol>
@endif
