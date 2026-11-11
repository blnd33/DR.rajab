<x-filament-panels::page>
    {{-- Choices for the questions about a period. --}}
    <div class="flex flex-wrap items-end gap-3">
        <label class="space-y-1 text-sm">
            <span class="block font-medium text-gray-700 dark:text-gray-200">{{ __('Period') }}</span>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="period">
                    @foreach ($periods as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>
        @if ($period === 'custom')
            <label class="space-y-1 text-sm">
                <span class="block font-medium text-gray-700 dark:text-gray-200">{{ __('From') }}</span>
                <x-filament::input.wrapper>
                    <x-filament::input type="date" wire:model.live="from" dir="ltr" />
                </x-filament::input.wrapper>
            </label>
            <label class="space-y-1 text-sm">
                <span class="block font-medium text-gray-700 dark:text-gray-200">{{ __('Until') }}</span>
                <x-filament::input.wrapper>
                    <x-filament::input type="date" wire:model.live="until" dir="ltr" />
                </x-filament::input.wrapper>
            </label>
        @endif
        @if (count($branches) > 1)
            <label class="space-y-1 text-sm">
                <span class="block font-medium text-gray-700 dark:text-gray-200">{{ __('Branch') }}</span>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="branch">
                        <option value="">{{ __('All my branches') }}</option>
                        @foreach ($branches as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
        @endif
        <label class="space-y-1 text-sm">
            <span class="block font-medium text-gray-700 dark:text-gray-200">{{ __('Currency') }}</span>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="currency">
                    @foreach ($currencies as $code)
                        <option value="{{ $code }}">{{ $code }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>
    </div>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        {{-- Ready-made questions --}}
        <nav class="space-y-4" aria-label="{{ __('Questions') }}">
            @foreach ($menu as $group => $block)
                <section class="space-y-2" aria-labelledby="qa-group-{{ $group }}">
                    <h2 id="qa-group-{{ $group }}" class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $block['label'] }}</h2>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($block['questions'] as $question)
                            <button type="button" wire:click="askQuestion('{{ $question['key'] }}')" wire:loading.attr="disabled"
                                    class="rounded-full bg-white px-3 py-1.5 text-start text-sm font-medium text-gray-700 shadow-sm ring-1 ring-gray-950/10 hover:bg-primary-50 hover:text-primary-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 dark:bg-white/5 dark:text-gray-200 dark:ring-white/10 dark:hover:bg-primary-500/10 dark:hover:text-primary-300">
                                {{ $question['label'] }}
                            </button>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </nav>

        {{-- Conversation --}}
        <div class="space-y-4 xl:col-span-2"
             x-data
             x-on:quick-answer.window="$nextTick(() => { $refs.end?.scrollIntoView({ behavior: 'smooth', block: 'end' }); if ($event.detail.focus) $refs.text.focus() })">
            <div class="space-y-4" aria-live="polite">
                @forelse ($messages as $message)
                    @if ($message['from'] === 'me')
                        <div wire:key="{{ $message['id'] }}" class="flex justify-end">
                            <p class="max-w-[85%] rounded-2xl rounded-ee-sm bg-primary-600 px-4 py-2 text-sm font-medium text-white">{{ $message['text'] }}</p>
                        </div>
                    @else
                        <div wire:key="{{ $message['id'] }}" class="space-y-3">
                            @if (filled($message['period'] ?? null))
                                <p class="text-xs font-medium text-gray-500 dark:text-gray-400"><bdi dir="ltr">{{ $message['period'] }}</bdi></p>
                            @endif
                            @if (filled($message['text'] ?? null))
                                <p class="max-w-[85%] rounded-2xl rounded-es-sm bg-white px-4 py-2 text-sm text-gray-800 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:text-gray-100 dark:ring-white/10">{{ $message['text'] }}</p>
                            @endif
                            @if (! empty($message['cards']))
                                <div class="grid grid-cols-[repeat(auto-fill,minmax(min(100%,17rem),1fr))] gap-4">
                                    @foreach ($message['cards'] as $card)
                                        <x-insight.fact-card :fact="$card" />
                                    @endforeach
                                </div>
                            @endif
                            @if (! empty($message['suggestions']))
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($message['suggestions'] as $suggestion)
                                        <button type="button" wire:click="askQuestion('{{ $suggestion['key'] }}')"
                                                class="rounded-full bg-primary-50 px-3 py-1.5 text-start text-sm font-medium text-primary-700 ring-1 ring-primary-600/20 hover:bg-primary-100 dark:bg-primary-500/10 dark:text-primary-300 dark:ring-primary-400/30">
                                            {{ $suggestion['label'] }}
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif
                @empty
                    <x-filament::section>
                        <div class="flex items-start gap-3">
                            <x-filament::icon icon="heroicon-o-chat-bubble-left-right" class="size-6 shrink-0 text-primary-500" />
                            <div class="space-y-1 text-sm text-gray-600 dark:text-gray-300">
                                <p class="font-semibold text-gray-950 dark:text-white">{{ __('Tap a question, or type or scan a code.') }}</p>
                                <p>{{ __('Codes: serial, SKU, barcode, lot, kit, instrument, document number (TRF-…, SRG-…, INV-…) or patient file number.') }}</p>
                                <p>{{ __('Answers come straight from the system. It does not understand full sentences — a few words are enough.') }}</p>
                            </div>
                        </div>
                    </x-filament::section>
                @endforelse
                <div wire:loading.flex wire:target="askQuestion, send" class="items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                    <x-filament::loading-indicator class="size-4" /> {{ __('Looking it up…') }}
                </div>
                <div x-ref="end"></div>
            </div>

            <form wire:submit="send" class="flex gap-2">
                <label for="qa-text" class="sr-only">{{ __('Type or scan a code or a few words') }}</label>
                <x-filament::input.wrapper class="flex-1">
                    <x-filament::input id="qa-text" x-ref="text" wire:model="text" autocomplete="off" autofocus
                                       maxlength="200" placeholder="{{ __('Type or scan a code or a few words') }}" />
                </x-filament::input.wrapper>
                <x-filament::button type="submit" icon="heroicon-m-paper-airplane">{{ __('Ask') }}</x-filament::button>
                @if ($messages !== [])
                    <x-filament::button type="button" color="gray" wire:click="clearChat">{{ __('Clear the answers') }}</x-filament::button>
                @endif
            </form>
        </div>
    </div>
</x-filament-panels::page>
