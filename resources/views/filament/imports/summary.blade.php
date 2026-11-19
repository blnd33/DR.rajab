{{-- Import summary: steps, counts and progress. Polls while the file is being checked or written. --}}
@php
    use App\Enums\ImportStatus;
    use App\Support\Locale\Display;

    /** @var \App\Models\DataImport $import */
    $import = $getRecord();
    $status = $import->status;
    $running = $status->isRunning();
    $steps = [
        ['label' => __('Uploaded'), 'done' => true],
        ['label' => __('Checked'), 'done' => ! in_array($status, [ImportStatus::Uploaded, ImportStatus::Validating], true)],
        ['label' => __('Confirmed'), 'done' => in_array($status, [ImportStatus::Confirmed, ImportStatus::Processing, ImportStatus::Completed], true)],
        ['label' => __('Imported'), 'done' => $status === ImportStatus::Completed],
    ];
    $cards = [
        ['label' => __('New'), 'value' => $import->new_count, 'color' => 'text-success-600', 'icon' => 'heroicon-o-plus-circle'],
        ['label' => __('Updated'), 'value' => $import->updated_count, 'color' => 'text-warning-600', 'icon' => 'heroicon-o-arrow-path'],
        ['label' => __('No change'), 'value' => $import->unchanged_count, 'color' => 'text-gray-500', 'icon' => 'heroicon-o-minus-circle'],
        ['label' => __('Errors'), 'value' => $import->error_count, 'color' => 'text-danger-600', 'icon' => 'heroicon-o-x-circle'],
    ];
@endphp

<div @if ($running) wire:poll.2s="refreshImport" @endif class="space-y-5">
    {{-- Steps --}}
    <ol class="flex flex-wrap items-center gap-x-3 gap-y-2 text-sm" aria-label="{{ __('Import steps') }}">
        @foreach ($steps as $i => $step)
            <li class="flex items-center gap-2">
                <span @class([
                    'flex size-6 items-center justify-center rounded-full text-xs font-bold',
                    'bg-primary-600 text-white' => $step['done'],
                    'bg-gray-200 text-gray-600 dark:bg-white/10 dark:text-gray-300' => ! $step['done'],
                ])>
                    @if ($step['done'])
                        @svg('heroicon-m-check', 'size-4')
                    @else
                        {{ Display::number($i + 1) }}
                    @endif
                </span>
                <span @class(['font-semibold' => $step['done']])>{{ $step['label'] }}</span>
                @if (! $loop->last)
                    @svg('heroicon-m-chevron-right', 'size-4 text-gray-400 rtl:rotate-180')
                @endif
            </li>
        @endforeach
    </ol>

    {{-- Status line --}}
    <div role="status" class="flex flex-wrap items-center gap-2">
        <x-filament::badge :color="$status->getColor()">{{ $status->getLabel() }}</x-filament::badge>
        <span class="text-sm text-gray-600 dark:text-gray-300">
            @switch($status)
                @case(ImportStatus::Uploaded)
                @case(ImportStatus::Validating)
                    {{ __('Checking every row… this page updates by itself.') }}
                    @break
                @case(ImportStatus::Previewed)
                    {{ $import->applicableCount() > 0
                        ? __('Check the rows below, then press "Import" at the top. Nothing has been changed yet.')
                        : __('Nothing new or changed to import.') }}
                    @break
                @case(ImportStatus::Confirmed)
                @case(ImportStatus::Processing)
                    {{ __('Importing… you can leave this page.') }}
                    @break
                @case(ImportStatus::Completed)
                    {{ __('Done. :new new and :updated updated rows were written.', ['new' => Display::number($import->new_count), 'updated' => Display::number($import->updated_count)]) }}
                    @break
                @case(ImportStatus::Failed)
                    {{ $import->failure_message }}
                    @break
                @case(ImportStatus::Cancelled)
                    {{ __('Cancelled. Nothing was imported.') }}
                    @break
            @endswitch
        </span>
    </div>

    {{-- Progress --}}
    @if (in_array($status, [ImportStatus::Confirmed, ImportStatus::Processing, ImportStatus::Completed], true))
        @php($percent = $status === ImportStatus::Completed ? 100 : $import->progressPercent())
        <div>
            <div class="mb-1 flex justify-between text-xs text-gray-600 dark:text-gray-300">
                <span>{{ __(':done of :total rows', ['done' => Display::number($import->processed_rows), 'total' => Display::number($import->applicableCount())]) }}</span>
                <span>{{ Display::number($percent) }}%</span>
            </div>
            <div class="h-2.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10"
                 role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}" aria-label="{{ __('Import progress') }}">
                <div class="h-full rounded-full bg-primary-600 transition-all duration-500 motion-reduce:transition-none" style="width: {{ $percent }}%"></div>
            </div>
        </div>
    @endif

    {{-- Counts --}}
    @if ($import->total_rows > 0)
        <dl class="grid grid-cols-2 gap-3 md:grid-cols-4">
            @foreach ($cards as $card)
                <div class="rounded-xl bg-gray-50 p-3 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                    <dt class="flex items-center gap-1.5 text-xs font-medium text-gray-600 dark:text-gray-300">
                        @svg($card['icon'], 'size-4 '.$card['color'])
                        {{ $card['label'] }}
                    </dt>
                    <dd class="mt-1 text-2xl font-bold">{{ Display::number($card['value']) }}</dd>
                </div>
            @endforeach
        </dl>
        @if ($import->warning_count > 0)
            <p class="text-sm text-warning-700 dark:text-warning-400">
                @svg('heroicon-m-exclamation-triangle', 'inline size-4')
                {{ trans_choice(':count row has a warning (imported, but check it).|:count rows have warnings (imported, but check them).', $import->warning_count, ['count' => Display::number($import->warning_count)]) }}
            </p>
        @endif
    @endif

    <p class="text-xs text-gray-500">
        {{ __('Uploaded by :name', ['name' => $import->user->name]) }} ·
        <time dir="ltr">{{ Display::dateTime($import->created_at) }}</time>
        @if ($import->branch)
            · {{ __('Default station') }}: {{ $import->branch->code }}
        @endif
    </p>
</div>
