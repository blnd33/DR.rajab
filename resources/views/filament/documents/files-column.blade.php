{{-- A document's files, each opened through the permission-checked attachment route. --}}
@php($files = $getRecord()->files)
<ul class="flex flex-col gap-1 px-3 py-2 text-sm">
    @forelse ($files as $file)
        <li>
            <a href="{{ route('attachments.show', $file) }}" target="_blank" rel="noopener"
               class="inline-flex items-center gap-1.5 text-primary-600 hover:underline dark:text-primary-400">
                @svg(str_starts_with((string) $file->mime, 'image/') ? 'heroicon-o-photo' : 'heroicon-o-document', 'size-4 shrink-0')
                <span class="max-w-64 truncate" dir="auto">{{ $file->original_name }}</span>
            </a>
        </li>
    @empty
        <li class="text-gray-400">—</li>
    @endforelse
</ul>
