{{-- "Fill from its list": what will go into the kit, per instrument type of the list. --}}
@php
    $todo = collect($plan['lines'])->filter(fn ($l) => $l['reuse'] + $l['register'] > 0);
@endphp
<div class="space-y-3 text-sm">
    @if ($plan['lines'] === [])
        <p class="text-gray-500 dark:text-gray-400">{{ __('The instrument set type\'s list has no instruments.') }}</p>
    @elseif ($todo->isEmpty())
        <p class="font-medium text-success-600 dark:text-success-400">{{ __('Every instrument on the list is already in the instrument set.') }}</p>
    @else
        <div class="max-h-80 overflow-auto rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-gray-950/5 text-gray-500 dark:border-white/10 dark:text-gray-400">
                        <th class="px-3 py-2 text-start font-medium">{{ __('Instrument type') }}</th>
                        <th class="px-3 py-2 text-end font-medium">{{ __('Missing') }}</th>
                        <th class="px-3 py-2 text-end font-medium">{{ __('From the shelf') }}</th>
                        <th class="px-3 py-2 text-end font-medium">{{ __('Registered new') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-950/5 dark:divide-white/10">
                    @foreach ($todo as $line)
                        <tr>
                            <td class="px-3 py-2">{{ $line['label'] }}</td>
                            <td class="px-3 py-2 text-end"><x-num :value="$line['reuse'] + $line['register']" /></td>
                            <td class="px-3 py-2 text-end"><x-num :value="$line['reuse']" /></td>
                            <td class="px-3 py-2 text-end"><x-num :value="$line['register']" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p>
            {{ __('In total: :reuse from the shelf, :register registered new.', [
                'reuse' => \App\Support\Locale\Display::number($plan['reuse']),
                'register' => \App\Support\Locale\Display::number($plan['register']),
            ]) }}
        </p>

        @if ($plan['register'] > 0 && ! $canRegister)
            <p class="font-medium text-danger-600 dark:text-danger-400">{{ __('Registering new instruments needs the permission ":permission".', ['permission' => __('Create and edit instruments')]) }}</p>
        @elseif ($plan['register'] > 0)
            <p class="text-gray-500 dark:text-gray-400">{{ __('New instruments get their own code (INS-…). Print their labels afterwards and stick them on.') }}</p>
        @endif
    @endif

    @if ($plan['products_missing'] > 0)
        <p class="text-warning-600 dark:text-warning-400">{{ __('Implants are not added this way: :count still missing — scan them in.', ['count' => \App\Support\Locale\Display::number($plan['products_missing'])]) }}</p>
    @endif
</div>
