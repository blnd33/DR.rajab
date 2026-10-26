<div class="space-y-5">
    <a href="{{ route('ops.tasks') }}" wire:navigate class="inline-flex min-h-11 items-center gap-1 text-sm font-semibold text-primary">
        @svg('heroicon-o-chevron-left', 'size-5 rtl:rotate-180')
        <span>{{ __('Tasks') }}</span>
    </a>

    <header>
        <h1 class="text-2xl font-bold">{{ __('New transfer') }}</h1>
        <p class="mt-1 text-muted-fg">{{ __('From :branch', ['branch' => $from?->code]) }}</p>
    </header>

    <form wire:submit="create" class="space-y-4">
        <fieldset class="space-y-2">
            <legend class="mb-1 text-sm font-semibold">{{ __('Send to') }}</legend>
            @foreach ($branches as $branch)
                <label wire:key="b-{{ $branch->id }}" class="ot-card flex min-h-14 cursor-pointer items-center gap-3 p-4 has-[:checked]:border-primary has-[:checked]:ring-2 has-[:checked]:ring-primary">
                    <input type="radio" wire:model="toBranchId" value="{{ $branch->id }}" class="size-5 accent-[var(--color-primary)]">
                    <span class="font-semibold">{{ $branch->code }}</span>
                    <span class="text-muted-fg">{{ $branch->name }}</span>
                </label>
            @endforeach
            @error('toBranchId') <p class="text-sm text-danger">{{ $message }}</p> @enderror
        </fieldset>

        <div>
            <label for="notes" class="mb-1 block text-sm font-semibold">{{ __('Note (optional)') }}</label>
            <textarea id="notes" wire:model="notes" rows="2" class="ot-input py-3"></textarea>
        </div>

        <button type="submit" class="ot-btn ot-btn-primary w-full" wire:loading.attr="disabled">
            @svg('heroicon-o-arrow-right', 'size-5 rtl:rotate-180')
            <span>{{ __('Next: scan the items') }}</span>
        </button>
    </form>
</div>
