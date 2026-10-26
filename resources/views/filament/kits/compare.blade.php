{{-- A kit against its kit type's template: expected, present, missing; extras below. --}}
@php($result = app(\App\Services\Assets\KitService::class)->compare($getRecord()))
<div class="space-y-3">
    @if ($result['lines'] === [])
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('This kit type has no template yet.') }}</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-start text-gray-500 dark:text-gray-400">
                        <th class="py-1 pe-3 text-start font-medium">{{ __('Expected') }}</th>
                        <th class="py-1 pe-3 text-end font-medium">{{ __('Quantity') }}</th>
                        <th class="py-1 pe-3 text-end font-medium">{{ __('In the kit') }}</th>
                        <th class="py-1 text-start font-medium">{{ __('Status') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-950/5 dark:divide-white/10">
                    @foreach ($result['lines'] as $line)
                        <tr>
                            <td class="py-2 pe-3">{{ $line['label'] }}@if (! $line['required']) <span class="text-xs text-gray-500">({{ __('optional') }})</span>@endif</td>
                            <td class="py-2 pe-3 text-end"><x-num :value="$line['expected']" /></td>
                            <td class="py-2 pe-3 text-end"><x-num :value="$line['present']" /></td>
                            <td class="py-2">
                                @if ($line['missing'] === 0)
                                    <x-filament::badge color="success" icon="heroicon-m-check-circle">{{ __('Complete') }}</x-filament::badge>
                                @else
                                    <x-filament::badge :color="$line['required'] ? 'danger' : 'warning'" icon="heroicon-m-minus-circle">{{ __(':count missing', ['count' => \App\Support\Locale\Display::number($line['missing'])]) }}</x-filament::badge>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($result['extras'] !== [])
        <p class="text-sm font-medium">{{ __('Not in the template') }}</p>
        <ul class="list-disc ps-5 text-sm">
            @foreach ($result['extras'] as $extra)
                <li>{{ $extra['label'] }} × <x-num :value="$extra['quantity']" /></li>
            @endforeach
        </ul>
    @endif

    <p class="text-sm font-semibold {{ $result['complete'] ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400' }}">
        {{ $result['complete'] ? __('The kit is complete.') : __('The kit is not complete — refill it from the branch shelf.') }}
    </p>
</div>
