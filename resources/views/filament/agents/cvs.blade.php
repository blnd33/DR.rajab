{{-- The CVs of a company agent (newest first), opened through the permission-checked attachment route. --}}
@php
    $record = $getRecord();
    $agent = $record instanceof \App\Models\User ? $record->agentProfile : $record;
    $cvs = $agent?->attachments()->where('kind', \App\Enums\AttachmentKind::Cv->value)->latest('created_at')->latest('id')->get() ?? collect();
@endphp

@if ($cvs->isEmpty())
    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No CV yet') }}</p>
@else
    <ul class="flex flex-col gap-2">
        @foreach ($cvs as $cv)
            <li class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                <a href="{{ route('attachments.show', $cv) }}" target="_blank" rel="noopener"
                   class="inline-flex items-center gap-2 font-medium text-primary-600 hover:underline dark:text-primary-400">
                    @svg('heroicon-o-document-text', 'size-5 shrink-0')
                    <span dir="auto">{{ $cv->original_name }}</span>
                </a>
                <span class="text-gray-500 dark:text-gray-400">{{ \App\Support\Locale\Display::date($cv->created_at) }}</span>
                @if ($loop->first && $cvs->count() > 1)
                    <span class="rounded-full bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-700 dark:bg-primary-500/10 dark:text-primary-300">{{ __('Latest') }}</span>
                @endif
            </li>
        @endforeach
    </ul>
@endif
