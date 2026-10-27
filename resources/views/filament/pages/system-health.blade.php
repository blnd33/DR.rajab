<x-filament-panels::page>
    @foreach ($groups as $group => $checks)
        <x-filament::section :heading="$group">
            <ul class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($checks as $check)
                    <li class="flex flex-wrap items-start justify-between gap-3 py-2">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold">{{ $check['label'] }}</p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $check['detail'] }}</p>
                        </div>
                        @switch($check['status'])
                            @case('ok')
                                <x-filament::badge color="success" icon="heroicon-m-check-circle">{{ __('OK') }}</x-filament::badge>
                                @break
                            @case('warning')
                                <x-filament::badge color="warning" icon="heroicon-m-exclamation-triangle">{{ __('Warning') }}</x-filament::badge>
                                @break
                            @default
                                <x-filament::badge color="danger" icon="heroicon-m-x-circle">{{ __('Critical') }}</x-filament::badge>
                        @endswitch
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endforeach

    <x-filament::section :heading="__('Versions')">
        <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
            @foreach ($versions as $label => $value)
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                    <dd class="font-semibold" dir="ltr">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
        <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">{{ __('Restoring a backup: see docs/RESTORE.md.') }}</p>
    </x-filament::section>
</x-filament-panels::page>
