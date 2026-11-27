{{--
    Page numbers above every list too, not only below it (the doctor's choice): the same bar as at
    the bottom, so the next page is one click away without scrolling down.
--}}
@props(['hasPagination' => false, 'records' => null, 'table' => null])

@if ($hasPagination && $table !== null)
    <div class="ot-pagination-top">
        <x-filament::pagination
            :extreme-links="$table->hasExtremePaginationLinks()"
            :page-options="$table->getPaginationPageOptions()"
            :paginator="$records"
        />
    </div>
@endif
