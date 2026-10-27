{{-- System problems on the dashboard (status = icon + text + colour, never colour alone). --}}
<x-filament-widgets::widget>
    @unless ($dismissed)
        <x-filament::section :heading="__('Needs attention')" icon="heroicon-o-exclamation-triangle" icon-color="warning">
            <x-slot name="afterHeader">
                <x-filament::button wire:click="dismiss" wire:loading.attr="disabled" color="gray" size="sm" icon="heroicon-m-eye-slash"
                    :tooltip="__('Hide for 24 hours, or until a new problem appears')">
                    {{ __('Dismiss') }}
                </x-filament::button>
            </x-slot>
            <ul class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($problems as $problem)
                    <li class="flex items-start gap-3 py-2">
                        @if ($problem['status'] === 'critical')
                            <x-filament::badge color="danger" icon="heroicon-m-x-circle">{{ __('Critical') }}</x-filament::badge>
                        @else
                            <x-filament::badge color="warning" icon="heroicon-m-exclamation-triangle">{{ __('Warning') }}</x-filament::badge>
                        @endif
                        <div class="min-w-0">
                            <p class="text-sm font-semibold">{{ $problem['label'] }}</p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $problem['detail'] }}</p>
                        </div>
                    </li>
                @endforeach
            </ul>
            <x-slot name="footer">
                <x-filament::link :href="$url" icon="heroicon-m-arrow-right" icon-position="after">{{ __('Open system health') }}</x-filament::link>
            </x-slot>
        </x-filament::section>
    @endunless
</x-filament-widgets::widget>
