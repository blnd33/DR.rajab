<div class="space-y-5">
    <a href="{{ route('ops.tasks') }}" wire:navigate class="inline-flex min-h-11 items-center gap-1 text-sm font-semibold text-primary">
        @svg('heroicon-o-chevron-left', 'size-5 rtl:rotate-180')
        <span>{{ __('Tasks') }}</span>
    </a>

    <header>
        <h1 class="text-2xl font-bold">{{ __('Count stock') }}</h1>
    </header>

    <form wire:submit="start" class="space-y-4">
        <fieldset class="space-y-2">
            <legend class="mb-1 text-sm font-semibold">{{ __('What do you count?') }}</legend>
            @foreach (\App\Enums\StockCountScope::cases() as $case)
                <label class="ot-card flex min-h-14 cursor-pointer items-center gap-3 p-4 has-[:checked]:border-primary has-[:checked]:ring-2 has-[:checked]:ring-primary">
                    <input type="radio" wire:model.live="scope" value="{{ $case->value }}" class="size-5">
                    @svg($case->getIcon(), 'size-5 text-primary')
                    <span class="font-semibold">{{ $case->getLabel() }}</span>
                </label>
            @endforeach
        </fieldset>

        @if ($scope === 'category')
            <div>
                <label for="cat" class="mb-1 block text-sm font-semibold">{{ __('Category') }}</label>
                <select id="cat" wire:model="categoryId" class="ot-input">
                    <option value="">—</option>
                    @foreach ($categories as $id => $label)
                        <option value="{{ $id }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        @if ($scope === 'location')
            <div>
                <label for="loc" class="mb-1 block text-sm font-semibold">{{ __('Shelf') }}</label>
                <select id="loc" wire:model="locationId" class="ot-input">
                    <option value="">—</option>
                    @foreach ($locations as $location)
                        <option value="{{ $location->id }}">{{ $location->label() }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        <button type="submit" class="ot-btn ot-btn-primary w-full py-4 text-lg" wire:loading.attr="disabled">
            @svg('heroicon-o-clipboard-document-check', 'size-6')
            <span>{{ __('Start counting') }}</span>
        </button>
    </form>
</div>
