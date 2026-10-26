{{-- Thumbnails of a record's attachments (opened through the permission-checked route). --}}
@php($attachments = $getRecord()->attachments()->latest('created_at')->get())
<div class="flex flex-wrap gap-3">
    @foreach ($attachments as $attachment)
        <a href="{{ route('attachments.show', $attachment) }}" target="_blank" rel="noopener"
           class="block overflow-hidden rounded-lg ring-1 ring-gray-950/10 dark:ring-white/10" title="{{ $attachment->original_name }}">
            @if (str_starts_with((string) $attachment->mime, 'image/'))
                <img src="{{ route('attachments.show', $attachment) }}" alt="{{ $attachment->original_name }}" class="size-28 object-cover" loading="lazy">
            @else
                <span class="flex size-28 items-center justify-center p-2 text-center text-xs">{{ $attachment->original_name }}</span>
            @endif
        </a>
    @endforeach
</div>
