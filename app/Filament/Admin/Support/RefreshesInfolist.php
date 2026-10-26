<?php

namespace App\Filament\Admin\Support;

/**
 * For view pages whose actions replace `$this->record` with a fresh copy: Filament keeps the
 * infolist built with the old record for the rest of the request, so the page would show
 * stale values until a reload. Rebuild it before rendering when the record changed.
 */
trait RefreshesInfolist
{
    public function renderingRefreshesInfolist(): void
    {
        $cached = $this->cachedSchemas['infolist'] ?? null;

        if ($cached !== null && $cached->getRecord(withParentComponentRecord: false) !== $this->getRecord()) {
            $this->cacheSchema('infolist');
        }
    }
}
